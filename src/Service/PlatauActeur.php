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
        $acteurs_crees = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($acteurs_crees));

        // On vérifie si la réponse donnée par Plat'AU contient bien des services consultables
        if (!\array_key_exists('servicesConsultables', $acteurs_crees) || empty($acteurs_crees['servicesConsultables'])) {
            throw new \Exception("L'enrôlement ne s'est pas correctement déroulé (absence de services consultables dans la réponse)");
        }

        // Récupération du service consultable créé
        $service_consultable_cree = array_shift($acteurs_crees['servicesConsultables']);
        \assert(\is_array($service_consultable_cree));
        if (!\array_key_exists('idActeur', $service_consultable_cree)) {
            throw new \Exception("L'enrôlement ne s'est pas correctement passé (absence d'idActeur dans la réponse)");
        }

        $acteur_id = (string) $service_consultable_cree['idActeur'];

        return $acteur_id;
    }

    /**
     * Récupération d'un acteur sur Plat'AU.
     */
    public function recuperationActeur(string $acteur_id) : array
    {
        // On récupère sur Plat'AU l'acteur via son ID.
        $acteurs = $this->pagination('post', 'acteurs/recherche', [
            'json' => [
                'idActeur' => $acteur_id,
            ],
        ]);

        // Si il n'y à pas d'acteur, alors la recherche à été infructueuse, et ce n'est pas normal.
        // On lève donc une exception.
        if (0 === $acteurs->getNbResults()) {
            throw new \Exception("L'acteur $acteur_id n'existe pas.");
        }

        // Récupération de l'acteur recherché
        $data   = (array) $acteurs->getCurrentPageResults();
        $acteur = array_shift($data);

        \assert(\is_array($acteur));

        return $acteur;
    }
}
