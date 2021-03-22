<?php

namespace App\Service;

use Exception;

final class PlatauActeur extends PlatauAbstract
{
    /**
     * Récupération d'un acteur sur Plat'AU.
     */
    public function recuperationActeur(string $acteur_id) : array
    {
        // On récupère sur Plat'AU l'acteur via son ID.
        $response = $this->request('post', 'acteurs/recherche', [
            'json' => [
                'idActeur' => $acteur_id,
            ],
        ]);

        // On décode le JSON nous permettant de récupérer les acteurs correspondants à la recherche.
        $acteurs = json_decode($response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        // Si il n'y à pas d'acteur, alors la recherche à été infructueuse, et ce n'est pas normal.
        // On lève donc une exception.
        if (0 === \count($acteurs)) {
            throw new Exception("L'acteur $acteur_id n'existe pas.");
        }

        // Récupération de l'acteur recherché
        $acteur = array_shift($acteurs);

        return $acteur;
    }
}
