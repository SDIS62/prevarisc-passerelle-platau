<?php

namespace App\Service;

use GuzzleHttp\Utils;
use GuzzleHttp\Middleware;
use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Client as HttpClient;
use kamermans\OAuth2\OAuth2Middleware;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack as HttpPipeline;
use kamermans\OAuth2\GrantType\ClientCredentials;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SyncplicityClient
{
    public const SYNCPLICITY_URL        = 'https://api.piste.gouv.fr/syncplicity/upload/';
    public const PISTE_ACCESS_TOKEN_URL = 'https://oauth.piste.gouv.fr/api/oauth/token';

    private HttpClient $http_client;
    private array $config;

    /**
     * Création d'une nouvelle instance du client Syncplicity.
     * La configuration doit contenir au moins :
     * - PISTE_CLIENT_ID (Le client_id de l'application inscrite sur PISTE pour communiquer avec Syncplicity)
     * - PISTE_CLIENT_SECRET (Le client_secret de l'application inscrite sur PISTE pour communiquer avec Syncplicity).
     *
     * La configuration peut contenir aussi :
     * - SYNCPLICITY_URL
     * - PISTE_ACCESS_TOKEN_URL
     **/
    public function __construct(array $config = [])
    {
        // Gestion des options de configuration.
        // (OptionsResolver allows to create an options system with required options, defaults, validation (type, value), normalization and more)
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['SYNCPLICITY_URL' => self::SYNCPLICITY_URL, 'PISTE_ACCESS_TOKEN_URL' => self::PISTE_ACCESS_TOKEN_URL]);
        $resolver->setRequired(['PISTE_CLIENT_ID', 'PISTE_CLIENT_SECRET']);
        $this->config = $resolver->resolve($config);

        // Initialisation du pipeline HTTP utilisé par Guzzle
        $stack = new HttpPipeline(Utils::chooseHandler());

        // Personnalisation du middleware de gestion d'erreur (permettant d'éviter de tronquer les messages d'erreur
        // renvoyés par Syncplicity)
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

        // Création du client HTTP servant à communiquer avec Syncplicity
        $this->http_client = new HttpClient([
            'base_uri' => $this->getConfig()['SYNCPLICITY_URL'],
            'timeout'  => 30.0,
            'handler'  => $stack,
            'auth'     => 'oauth',
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
     * Lancement d'une requête vers Syncplicity.
     */
    public function request(string $method, string $uri = '', array $options = []) : ResponseInterface
    {
        // Suppression du leading slash car cela peut rentrer en conflit avec la base uri
        $uri = ltrim($uri, '/');

        return $this->http_client->request($method, $uri, $options);
    }

    /**
     * Upload d'un document.
     */
    public function upload(string $file_contents, string $file_name) : array
    {
        // Si le fichier fait moins de 10mo, alors on lance un upload simple
        if ($this->getFileSize($file_contents) < 10 * 10 ** 6) {
            // On lance l'upload
            $response = $this->request('POST', 'upload', [
                'multipart' => [
                    [
                        'name'     => 'fileData',
                        'contents' => $file_contents,
                        'filename' => $file_name,
                    ],
                ],
            ]);

            // On décode la réponse JSON
            $json = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
            \assert(
                \is_array($json) &&
                \array_key_exists('data_file_id', $json) &&
                \array_key_exists('data_file_version_id', $json) &&
                \array_key_exists('VirtualFolderId', $json),
                "L'upload du fichier Syncplicity est incorrect"
            );

            return $json;
        }

        // Si nous sommes là, alors le fichier à upload fait plus de 10mo.
        // Nous devons tout d'abord créer un ticket de pre-upload pour annoncer à Syncplicity
        // que nous allons envoyer un fichier.
        $response          = $this->request('GET', 'pre-upload');
        $ticket_pre_upload = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(
            \is_array($ticket_pre_upload) &&
            \array_key_exists('VirtualFolderId', $ticket_pre_upload) &&
            \array_key_exists('Authorization_for_upload', $ticket_pre_upload) &&
            \array_key_exists('AppKey', $ticket_pre_upload) &&
            \array_key_exists('Folder_Name', $ticket_pre_upload) &&
            \array_key_exists('Storage_URL', $ticket_pre_upload),
            'Le ticket pre upload Syncplicity est incorrect'
        );

        // On créé un nouveau client HTTP afin de lancer une requête sur le Storage_URL du ticket pre-upload
        $http_client = new HttpClient([
            'base_uri' => $ticket_pre_upload['Storage_URL'],
        ]);

        // On envoie le fichier en multipart en utilisant les informations du ticket upload
        $response = $http_client->request('POST', 'v2/mime/files', [
            'query' => [
                'filepath' => (string) $ticket_pre_upload['Folder_Name'].'/'.urlencode($file_name),
            ],
            'headers' => [
                'AppKey'        => $ticket_pre_upload['AppKey'],
                'Authorization' => $ticket_pre_upload['Authorization_for_upload'],
            ],
            'multipart' => [
                [
                    'name'     => 'fileData',
                    'contents' => $file_contents,
                    'filename' => $file_name,
                ],
                [
                    'name'     => 'virtualFolderId',
                    'contents' => $ticket_pre_upload['VirtualFolderId'],
                ],
                [
                    'name'     => 'SHA-256',
                    'contents' => hash('sha256', $file_contents),
                ],
                [
                    'name'     => 'sessionKey',
                    'contents' => $ticket_pre_upload['Authorization_for_upload'],
                ],
                [
                    'name'     => 'filename',
                    'contents' => $file_name,
                ],
            ],
        ]);

        $json_file = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(
            \is_array($json_file) &&
            \array_key_exists('data_file_id', $json_file) &&
            \array_key_exists('data_file_version_id', $json_file),
            "L'upload du fichier Syncplicity est incorrect"
        );

        return $json_file + [
            'VirtualFolderId' => $ticket_pre_upload['VirtualFolderId'],
        ];
    }

    /**
     * Calcul de la taille du contenu d'un fichier.
     * Retourne le résultat en octets.
     */
    private static function getFileSize(string $file_contents) : int
    {
        return false === mb_detect_encoding($file_contents, strict: true) ? \mb_strlen($file_contents) : mb_strlen($file_contents);
    }
}
