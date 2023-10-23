<?php

namespace App\Service;

use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;

final class PlatauPiece extends PlatauAbstract
{
    /**
     * Upload un document sur Syncplicity et formatte le retour pour une utilisation dans une requête d'un objet métier du dossier.
     */
    public function uploadDocument(string $filename, string $file_contents, int $type_document) : array
    {
        $syncplicity = $this->getSyncplicity();
        if (null === $syncplicity) {
            throw new \Exception('Le client Syncplicity doit être activé pour poursuivre cette action.');
        }

        // On envoie le contenu du fichier dans Plat'AU via Syncplicity
        $file = $syncplicity->upload($file_contents, $filename);

        \assert(\array_key_exists('data_file_id', $file));
        $syncplicity_file_id = (string) $file['data_file_id'];

        \assert(\array_key_exists('VirtualFolderId', $file));
        $syncplicity_folder_id = (string) $file['VirtualFolderId'];

        $document = [
            'fileId'               => $syncplicity_file_id,
            'folderId'             => $syncplicity_folder_id,
            'dtProduction'         => (new \DateTime())->format('Y-m-d'),
            'idActeurProducteur'   => (string) $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT'],
            'algoHash'             => 'SHA-512',
            'hash'                 => hash('sha512', $file_contents),
            'nomTypeDocument'      => $type_document,  // Nomenclature TYPE_DOCUMENT
            'nomTypeProducteurDoc' => 1,  // Nomenclature NATURE_PIECE. Toujours à 1 : "Personne jouant un rôle dans un dossier"
        ];

        return $document;
    }

    /*
     * Télécharge un document
     */
    public function download(array $piece) : ResponseInterface
    {
        // Création d'un client HTTP à part permettant de récupérer les fichiers Plat'AU
        $http_client = new HttpClient();

        \assert(\array_key_exists('url', $piece));
        \assert(\array_key_exists('token', $piece));

        // On lance la requête HTTP de récupération
        return $http_client->request('GET', (string) $piece['url'], [
            'headers' => [
                'Authorization' => 'Bearer '.(string) $piece['token'],
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
