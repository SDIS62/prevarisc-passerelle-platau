<?php

namespace App\Service;

use Exception;

final class PlatauHealthcheck extends PlatauAbstract
{
    /**
     * Vérifie la santé du service Plat'AU.
     * Si une erreur est détectée, une Exception est levée.
     */
    public function healthcheck() : bool
    {
        // On envoie une requête à l'endpoint healthcheck de Plat'AU
        $response = $this->request('get', 'healthcheck');

        // Si on se trouve là, c'est qu'on a réussi à contacter Plat'AU.
        // On vient donc récupérer les status des services Plat'AU (identifié dans la réponse de l'healthcheck
        // par etatGeneral et etatBdd)
        $healthcheck_results = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($healthcheck_results));

        $status = array_filter($healthcheck_results, fn ($key) => \in_array($key, ['etatGeneral', 'etatBdd']), \ARRAY_FILTER_USE_KEY);

        // On va vérifier si les status sont OK, sinon, on déclenche une exception
        foreach ($status as $etat) {
            if (true !== $etat) {
                throw new \Exception("$etat de Plat'AU non fonctionnel actuellement.");
            }
        }

        return true;
    }
}
