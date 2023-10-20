<?php

namespace App\Service;

use GuzzleHttp\Utils;
use GuzzleHttp\Middleware;
use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Client as HttpClient;
use GuzzleRetry\GuzzleRetryMiddleware;
use kamermans\OAuth2\OAuth2Middleware;
use Psr\Http\Message\RequestInterface;
use Pagerfanta\Adapter\CallbackAdapter;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack as HttpPipeline;
use kamermans\OAuth2\GrantType\ClientCredentials;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class PlatauAbstract
{
    public const PLATAU_URL             = 'https://api.aife.economie.gouv.fr/mtes/platau/v9/';
    public const PISTE_ACCESS_TOKEN_URL = 'https://oauth.piste.gouv.fr/api/oauth/token';

    private HttpClient $http_client;
    private array $config;
    private ?SyncplicityClient $syncplicity = null;

    /**
     * Création d'une nouvelle instance d'un service Platau.
     * La configuration doit contenir au moins :
     * - PISTE_CLIENT_ID (Le client_id de l'application inscrite sur PISTE pour communiquer avec Plat'AU)
     * - PISTE_CLIENT_SECRET (Le client_secret de l'application inscrite sur PISTE pour communiquer avec Plat'AU)
     * - PLATAU_ID_ACTEUR_APPELANT (L'ID de l'acteur Plat'AU a utiliser lros des appels Plat'AU).
     *
     * La configuration peut contenir aussi :
     * - PLATAU_URL
     * - PISTE_ACCESS_TOKEN_URL
     **/
    final public function __construct(array $config = [])
    {
        // Gestion des options de configuration.
        // (OptionsResolver allows to create an options system with required options, defaults, validation (type, value), normalization and more)
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['PLATAU_URL' => self::PLATAU_URL, 'PISTE_ACCESS_TOKEN_URL' => self::PISTE_ACCESS_TOKEN_URL, 'PLATAU_ID_ACTEUR_APPELANT' => null]);
        $resolver->setRequired(['PISTE_CLIENT_ID', 'PISTE_CLIENT_SECRET']);
        $this->config = $resolver->resolve($config);

        // Initialisation du pipeline HTTP utilisé par Guzzle
        $stack = new HttpPipeline(Utils::chooseHandler());

        // Retry statregy : on va demander au client Plat'AU d'essayer plusieurs fois une requête qui pose problème
        // afin d'éviter de tomber à cause d'un problème de connexion ponctuel (exemple : Connection refused for URI)
        /** @var callable(callable): callable $retry_middleware */
        $retry_middleware = GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 5,
            'retry_on_status'    => [429, 503, 500],
            'on_retry_callback'  => function (int $attemptNumber, float $delay, RequestInterface &$request, array &$_options, ?ResponseInterface $response) {
                $message = sprintf(
                    "Un problème est survenu lors de la requête à %s : Plat'AU a répondu avec un code %s. Nous allons attendre %s secondes avant de réessayer. Ceci est l'essai numéro %s.",
                    $request->getUri()->getPath(),
                    $response ? $response->getStatusCode() : 'xxx',
                    number_format($delay, 2),
                    $attemptNumber
                );
                echo $message.\PHP_EOL;
            },
        ]);
        $stack->push($retry_middleware);

        // Personnalisation du middleware de gestion d'erreur (permettant d'éviter de tronquer les messages d'erreur
        // renvoyés par Plat'AU)
        $stack->push(Middleware::httpErrors(new BodySummarizer(16384)), 'http_errors');

        // Autorise le suivi des redirections
        /** @var callable(callable): callable $redirect_middleware */
        $redirect_middleware = Middleware::redirect();
        $stack->push($redirect_middleware, 'allow_redirects');

        // Prépare les requests contenants un body, en ajoutant Content-Length, Content-Type, et les entêtes attendues.
        /** @var callable(callable): callable $prepare_body_middleware */
        $prepare_body_middleware = Middleware::redirect();
        $stack->push($prepare_body_middleware, 'prepare_body');

        // Middleware : OAuth2 auth (https://developer.aife.economie.gouv.fr)
        $stack->push(new OAuth2Middleware(new ClientCredentials(new HttpClient(['base_uri' => $this->getConfig()['PISTE_ACCESS_TOKEN_URL']]), [
            'client_id'     => $this->getConfig()['PISTE_CLIENT_ID'],
            'client_secret' => $this->getConfig()['PISTE_CLIENT_SECRET'],
        ])));

        // Gestion des entêtes HTTP et définition de l'id acteur appelant (si il existe dans la config)
        $headers = ['Content-Type' => 'application/json'];
        if (null !== $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT']) {
            $headers += ['Id-Acteur-Appelant' => $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT']];
        }

        // Création du client HTTP servant à communiquer avec Plat'AU
        $this->http_client = new HttpClient([
            'base_uri' => $this->getConfig()['PLATAU_URL'],
            'timeout'  => 30.0,
            'handler'  => $stack,
            'auth'     => 'oauth',
            'headers'  => $headers,
        ]);
    }

    /**
     * Active le service Syncplicity pour l'upload de document.
     */
    public function enableSyncplicity(SyncplicityClient $client) : void
    {
        $this->syncplicity = $client;
    }

    /**
     * Récupération du client Syncplicity.
     */
    public function getSyncplicity() : ?SyncplicityClient
    {
        return $this->syncplicity;
    }

    /**
     * Récupération des options de configuration.
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * Lancement d'une requête vers Plat'AU.
     */
    public function request(string $method, string $uri = '', array $options = []) : ResponseInterface
    {
        // Suppression du leading slash car cela peut rentrer en conflit avec la base uri
        $uri = ltrim($uri, '/');

        return $this->http_client->request($method, $uri, $options);
    }

    /**
     * Traitement de la pagination Plat'AU.
     *
     * Attention, le système de pagination est inconsistant dans Plat'AU. Certains endpoints prennent
     * en charge un numéro de page et une limite d'éléments retournés, la recherche des notifications
     * fonctionne grâce à un système de curseur, et d'autres ne fonctionnent qu'avec un numéro de page
     * avec un nombre d'éléments fixés et non modifiable. (ps : d'ailleurs, l'API Plat'AU crash dès qu'un endpoint
     * pagniné avec un nombre d'éléments fixés retourne trop d'éléments, cela fait planter leur base de données MongoDB,
     * voir https://github.com/SDIS62/prevarisc-passerelle-platau/issues/41).
     *
     * Les endpoints qui supportent complètement la pagination sont :
     *   - /propositionsDecisionsUrba/recherche
     *   - /livraisonNumerisation/recherche
     *   - /declarationsOuvertureChantier/recherche
     *   - /declarationsAchevementTravaux/recherche
     *   - /lettresAuxPetitionnaires/recherche
     *   - /consultations/recherche
     *   - /dossiers/recherche
     */
    protected function pagination(string $method, string $uri = '', array $options = []) : PlatauCollection
    {
        $adapter = new CallbackAdapter(
            // A callable to count the number items in the list
            function () use ($method, $uri, $options) : int {
                // On va donner un nbElementsParPage à 100 par défaut, pour éviter de faire crash Plat'AU. Mais nous vérifierons quand même
                // le nombre de résultats que Plat'AU nous renverra car nbElementsParPage est inconsistant en fonction des endpoints
                // paginés.
                $premiere_page = json_decode($this->request($method, $uri, array_merge_recursive($options, ['query' => ['numeroPage' => 0, 'nbElementsParPage' => 100]]))->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($premiere_page));
                \assert(\array_key_exists('nombrePages', $premiere_page) && \array_key_exists('resultats', $premiere_page) && \is_array($premiere_page['resultats']), "La pagination renvoyée par Plat'AU est incorrecte");
                if (0 === $premiere_page['nombrePages']) { // La première page pour Plat'AU est la page numéro ... 0 (erf ...)
                    return \count($premiere_page['resultats']);
                }
                $total_sans_la_derniere_page = \count($premiere_page['resultats']) * ((int) $premiere_page['nombrePages'] - 1);
                $derniere_page               = json_decode($this->request($method, $uri, array_merge_recursive($options, ['query' => ['numeroPage' => (int) $premiere_page['nombrePages'] - 1, 'nbElementsParPage' => 100]]))->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($derniere_page));
                \assert(\array_key_exists('nombrePages', $derniere_page) && \array_key_exists('resultats', $derniere_page) && \is_array($derniere_page['resultats']), "La pagination renvoyée par Plat'AU est incorrecte");
                $total = $total_sans_la_derniere_page + \count($derniere_page['resultats']);
                \assert($total >= 0, 'La pagination renvoyée par Plat\'AU est incorrecte : le total est négatif');

                return $total;
            },
            // A callable to get the items for the current page in the paginated list
            function (int $offset, int $length) use ($method, $uri, $options) : iterable {
                $max_per_page = \in_array(ltrim($uri, '/'), [
                    'propositionsDecisionsUrba/recherche',
                    'livraisonNumerisation/recherche',
                    'declarationsOuvertureChantier/recherche',
                    'declarationsAchevementTravaux/recherche',
                    'lettresAuxPetitionnaires/recherche',
                    'consultations/recherche',
                    'dossiers/recherche',
                ]) ? $length : 500;
                $results      = [];
                $page_debut   = (int) floor($offset / $max_per_page);
                $page_fin     = (int) floor(($offset + $length - 1) / $max_per_page);
                for ($page = $page_debut; $page <= $page_fin; ++$page) {
                    $response = $this->request($method, $uri, array_merge_recursive($options, ['query' => ['numeroPage' => $page, 'nbElementsParPage' => $max_per_page]]));
                    $json     = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
                    \assert(\is_array($json));
                    \assert(\array_key_exists('resultats', $json) && \is_array($json['resultats']), "La pagination renvoyée par Plat'AU est incorrecte");
                    $results = $results + $json['resultats'];
                }

                return \array_slice($results, $offset % $max_per_page, $length);
            }
        );

        return new PlatauCollection($adapter);
    }
}
