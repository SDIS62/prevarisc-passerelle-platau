<?php

namespace App\Service;

final class PlatauPiece extends PlatauAbstract
{
    /*
     * Télécharge un document
     */
    public function download(array $piece) : string
    {
        $file_contents = file_get_contents($piece['url'], false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization: Bearer '.$piece['token']."\r\n",
            ],
        ]));

        return $file_contents;
    }
}
