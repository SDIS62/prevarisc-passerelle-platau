<?php

namespace App\Service;

use DateTime;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;

final class PlatauPiece extends PlatauAbstract
{
    /**
     * Associe un fichier hébergé sur une instance syncplicity à une pièce Plat'AU.
     */
    public function ajouterPieceDepuisFichierSyncplicity(
        string $numero,
        string $dossier_id,
        string $version_no,
        string $nature,
        string $type,
        string $syncplicity_file_id,
        string $syncplicity_folder_id,
        string $file_hash_sha512 = null
    ) : array {
        // On formatte la pièce Plat'AU
        $piece = [
            'noPiece'         => $numero,
            'nomNaturePiece'  => $nature, // Nomenclature NATURE_PIECE
            'nomTypePiece'    => $type, // Nomenclature TYPE_PIECE
            'fileId'          => $syncplicity_file_id,
            'folderId'        => $syncplicity_folder_id,
            'dtProduction'    => (new DateTime())->format('Y-m-d'),
        ];

        // Si le hash du fichier correspondant à la pièce est donné, on l'envoie à Plat'AU pour qu'il
        // s'assure que le fichier correspond bien
        if (null !== $file_hash_sha512) {
            $piece += [
                'algoHash' => 'SHA-512',
                'hash'     => $file_hash_sha512,
            ];
        }

        // Verse les informations relatives aux pièces constitutives d'un dossier
        $response = $this->request('POST', 'pieces', [
            'json' => [
                [
                    'idDossier' => $dossier_id,
                    'noVersion' => $version_no,
                    'pieces'    => [$piece],
                ],
            ],
        ]);

        $piece = json_decode($response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        return $piece;
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
