<?php

namespace App\Service;

use Exception;

final class PlatauActeur extends PlatauAbstract
{
    /**
     * Enrôlement dans Plat'AU d'un nouvel acteur de type service consultable.
     * Cette méthode retourne l'ID Acteur du service consultable créé.
     */
    public function enrolerServiceConsultable(string $designation, string $mail, string $siren) : string
    {
        // On ajoute sur Plat'AU l'acteur de type 'servicesConsultables'.
        $response = $this->request('post', '/enrolement/acteurs', [
            'json' => [
                'servicesConsultables' => [
                    [
                        'designationActeur' => $designation,
                        'mail'              => $mail,
                        'siren'             => $siren,
                    ],
                ],
            ],
        ]);

        // On décode le JSON nous permettant de récupérer les acteurs créés.
        $acteurs_crees = json_decode($response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        // On vérifie si la réponse donnée par Plat'AU contient bien des services consultables
        if (!\array_key_exists('servicesConsultables', $acteurs_crees) || empty($acteurs_crees['servicesConsultables'])) {
            throw new Exception("L'enrôlement ne s'est pas correctement déroulé (absence de services consultables dans la réponse)");
        }

        // Récupération du service consultable créé
        $service_consultable_cree = array_shift($acteurs_crees['servicesConsultables']);
        if (!\array_key_exists('idActeur', $service_consultable_cree)) {
            throw new Exception("L'enrôlement ne s'est pas correctement passé (absence d'idActeur dans la réponse)");
        }

        return $service_consultable_cree['idActeur'];
    }

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
