<?php

namespace App\Service;

final class PlatauAvis extends PlatauAbstract
{
    /**
     * Recherche de plusieurs avis.
     */
    public function rechercheAvis(array $params = []) : array
    {
        // On recherche l'avis en fonction des critères de recherche
        $paginator = $this->pagination('post', 'avis/recherche', [
            'json' => [
                'criteresSurConsultations' => $params,
            ],
        ]);

        $avis = [];

        foreach ($paginator->autoPagingIterator() as $avis_simple) {
            \assert(\is_array($avis_simple));

            $avis[] = $this->parseAvis($avis_simple);
        }

        return $avis;
    }

    /**
     * Récupération d'un avis d'une consultation.
     */
    public function getAvisForConsultation(string $consultation_id, array $params = []) : array
    {
        // On recherche les avis  de la consultation demandée
        $avis = $this->rechercheAvis(['idConsultation' => $consultation_id] + $params);

        // Si la liste des avis est vide, alors on lève une erreur (la recherche n'a rien donné)
        if (empty($avis)) {
            throw new \Exception("l'avis $consultation_id est introuvable selon les critères de recherche");
        }

        // On vient récupérer l'avis qui nous interesse dans le tableau des résultats
        $avis_simple = array_shift($avis);

        \assert(\is_array($avis_simple));

        return $avis_simple;
    }

    /**
     * Retourne un tableau représentant l'avis.
     */
    private function parseAvis(array $avis) : array
    {
        // On vient récupérer les détails de l'avis recherché, qui, pour une raison étrange, se trouvent
        // dans un tableau de avis auxquelles le dossier lié est rattaché.
        // Pour que ce soit plus logique, on les place au même niveau que 'projet' et 'dossier'.
        $consultation_id = (string) $avis['dossier']['avis'][0]['idConsultation'];
        $avis            = array_merge($avis, current(array_filter($avis['dossier']['avis'], fn (array $c) => $c['idConsultation'] === $consultation_id)));

        return $avis;
    }
}
