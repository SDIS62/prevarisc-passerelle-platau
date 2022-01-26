<?php

namespace App\Service;

use GuzzleHttp\Client as HttpClient;
use kamermans\OAuth2\OAuth2Middleware;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack as HttpPipeline;
use kamermans\OAuth2\GrantType\ClientCredentials;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class PlatauAbstract
{
    public const PLATAU_URL             = 'https://api.aife.economie.gouv.fr/mtes/platau/v6/';
    public const PISTE_ACCESS_TOKEN_URL = 'https://oauth.aife.economie.gouv.fr/api/oauth/token';

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
    public function __construct(array $config = [])
    {
        // Gestion des options de configuration.
        // (OptionsResolver allows to create an options system with required options, defaults, validation (type, value), normalization and more)
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['PLATAU_URL' => self::PLATAU_URL, 'PISTE_ACCESS_TOKEN_URL' => self::PISTE_ACCESS_TOKEN_URL, 'PLATAU_ID_ACTEUR_APPELANT' => null]);
        $resolver->setRequired(['PISTE_CLIENT_ID', 'PISTE_CLIENT_SECRET']);
        $this->config = $resolver->resolve($config);

        // Initialisation du pipeline HTTP utilisé par Guzzle
        $stack = HttpPipeline::create();

        // Middleware : OAuth2 auth (https://developer.aife.economie.gouv.fr)
        $stack->push(new OAuth2Middleware(new ClientCredentials(new HttpClient(['base_uri' => $this->getConfig()['PISTE_ACCESS_TOKEN_URL']]), [
            'client_id'     => $this->getConfig()['PISTE_CLIENT_ID'],
            'client_secret' => $this->getConfig()['PISTE_CLIENT_SECRET'],
        ])));

        // Gestion des entêtes HTTP et définition de l'id acteur appelant (si il existe dans la config)
        $headers = ['Content-Type' => 'application/json'];
        if(null !== $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT']) {
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
     * Récupération des options de configuration.
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * Lancement d'une requête vers Plat'AU.
     */
    public function request(string $method, $uri = '', array $options = []) : ResponseInterface
    {
        // Suppression du leading slash car cela peut rentrer en conflit avec la base uri
        $uri = ltrim($uri, '/');

        return $this->http_client->request($method, $uri, $options);
    }
}
