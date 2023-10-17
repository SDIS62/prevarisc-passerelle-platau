<?php

namespace App\Service;

use DateTime;
use Exception;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;

final class PlatauPiece extends PlatauAbstract
{
    private Prevarisc $prevarisc_service;
    private SyncplicityClient $syncplicity_client;

    public function __construct(array $config, Prevarisc $prevarisc_service, SyncplicityClient $syncplicity_client)
    {
        $this->prevarisc_service  = $prevarisc_service;
        $this->syncplicity_client = $syncplicity_client;
        parent::__construct($config);
    }

    /**
     * Upload les documents sur Syncplicity et formatte le retour pour une utilisation dans une requête d'un objet métier du dossier.
     */
    public function formatDocuments(array $pieces, int $type_document) : array
    {
        if (0 === \count($pieces)) {
            return [];
        }

        $id_acteur = $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT'];
        $documents = [];

        foreach ($pieces as $piece) {
            try {
                $file_contents = $this->prevarisc_service->recupererFichierPhysique($piece['ID_PIECEJOINTE'], $piece['EXTENSION_PIECEJOINTE']);
                $file_name     = $piece['NOM_PIECEJOINTE'].$piece['EXTENSION_PIECEJOINTE'];
                $file          = $this->syncplicity_client->upload($file_contents, $file_name);
                $this->prevarisc_service->changerStatutPiece($piece['ID_PIECEJOINTE'], 'exported');
            } catch (Exception $e) {
                $this->prevarisc_service->changerStatutPiece($piece['ID_PIECEJOINTE'], 'on_error');

                continue;
            }

            \assert(\array_key_exists('data_file_id', $file));
            $syncplicity_file_id = $file['data_file_id'];

            \assert(\array_key_exists('VirtualFolderId', $file));
            $syncplicity_folder_id = $file['VirtualFolderId'];

            $documents[] = [
                'fileId'               => (string) $syncplicity_file_id,
                'folderId'             => (string) $syncplicity_folder_id,
                'algoHash'             => 'SHA-512',
                'dtProduction'         => (new DateTime())->format('Y-m-d'),
                'hash'                 => hash('sha512', $file_contents),
                'idActeurProducteur'   => $id_acteur,
                'nomTypeDocument'      => $type_document,
                'nomTypeProducteurDoc' => 1, // Personne jouant un rôle dans un dossier
            ];
        }

        return $documents;
    }

    /*
     * Télécharge un document
     */
    public function download(array $piece) : ResponseInterface
    {
        // Création d'un client HTTP à part permettant de récupérer les fichiers Plat'AU
        $http_client = new HttpClient();

        // On lance la requête HTTP de récupération
        return $http_client->request('GET', $piece['url'], [
            'headers' => [
                'Authorization' => 'Bearer '.$piece['token'],
            ],
        ]);
    }

    /*
     * Essaie de deviner une extension de fichier Plat'AU.
     * Pour l'instant, Plat'AU ne renvoie que des Content-Type 'application/octet-stream' ce qui nous empêche d'avoir une
     * détection de l'extension sur un type MIME correct.
     * Une première solution est de vérfier l'existence de l'entête 'Content-Disposition', et d'en extraire l'extension.
     * Si cette méthode n'arrive pas à deviner, elle renvoie null.
     */
    public static function getExtensionFromHttpResponse(ResponseInterface $http_response) : ?string
    {
        if (!$http_response->hasHeader('Content-Disposition')) {
            return null;
        }

        $content_disposition_header = $http_response->getHeaderLine('Content-Disposition');

        $extension_regex   = "/filename[^;=\n]*=(?'filename'(['\"]).*?\2|[^;\n.]*).(?'extension'\w*)/";
        $extension_matches = [];
        preg_match($extension_regex, $content_disposition_header, $extension_matches, \PREG_UNMATCHED_AS_NULL);

        // Si aucune extension n'est trouvée, on retourne null
        if (!\array_key_exists('extension', $extension_matches)) {
            return null;
        }

        return $extension_matches['extension'];
    }
}
