<?php

namespace App\Service;

use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;

final class PlatauPiece extends PlatauAbstract
{
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
        if(!array_key_exists('extension', $extension_matches)) {
            return null;
        }

        return $extension_matches['extension'];
    }
}
