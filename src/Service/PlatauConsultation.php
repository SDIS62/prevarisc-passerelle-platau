<?php

namespace App\Service;

use Datetime;
use Exception;
use DateInterval;

final class PlatauConsultation extends PlatauAbstract
{
    private PlatauPiece $piece_service;

    public function __construct(array $config, PlatauPiece $piece_service)
    {
        $this->piece_service = $piece_service;
        parent::__construct($config);
    }

    /**
     * Recherche de plusieurs consultations.
     */
    public function rechercheConsultations(array $params = [], string $order_by = 'DT_DEPOT', string $sort = 'DESC') : array
    {
        // On recherche la consultation en fonction des critères de recherche
        $paginator = $this->pagination('post', 'consultations/recherche', [
            'json' => [
                'criteresSurConsultations' => $params,
            ],
            'query' => [
                'colonneTri' => $order_by,
                'sensTri'    => $sort,
            ],
        ]);

        $consultations = [];

        foreach ($paginator->autoPagingIterator() as $consultation) {
            $consultations[] = $this->parseConsultation($consultation);
        }

        return $consultations;
    }

    /**
     * Récupération d'une consultation.
     */
    public function getConsultation(string $consultation_id, array $params = []) : array
    {
        // On recherche la consultation demandée
        $consultations = $this->rechercheConsultations(['idConsultation' => $consultation_id] + $params);

        // Si la liste des consultations est vide, alors on lève une erreur (la recherche n'a rien donné)
        if (empty($consultations)) {
            throw new Exception("la consultation $consultation_id est introuvable selon les critères de recherche");
        }

        // On vient récupérer la consultation qui nous interesse dans le tableau des résultats
        $consultation  = array_shift($consultations);

        return $consultation;
    }

    /**
     * Récupération des pièces d'une consultation.
     */
    public function getPieces(string $consultation_id) : array
    {
        // On recherche la consultation associée pour récupérer le dossier lié
        $consultation = $this->getConsultation($consultation_id);

        // On recherche maintenant l'ensemble des pièces liées au dossier
        $response = $this->request('get', 'dossiers/'.$consultation['dossier']['idDossier'].'/pieces');

        // On vient récupérer les pièces qui nous interesse dans la réponse des résultats de recherche
        $pieces = json_decode($response->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        return $pieces;
    }

    /**
     * Retourne un tableau représentant la consultation.
     */
    private function parseConsultation(array $consultation) : array
    {
        // On vient récupérer les détails de la consultation recherchée, qui, pour une raison étrange, se trouvent
        // dans un tableau de consultations auxquelles le dossier lié est rattaché.
        // Pour que ce soit plus logique, on les place au même niveau que 'projet' et 'dossier'.
        $consultation_id = $consultation['dossier']['consultations'][0]['idConsultation'];
        $consultation    = array_merge($consultation, current(array_filter($consultation['dossier']['consultations'], fn ($c) => $c['idConsultation'] === $consultation_id)));

        return $consultation;
    }

    /**
     * Envoi d'une PEC sur une consultation.
     */
    public function envoiPEC(string $consultation_id, bool $est_positive = true, DateInterval $date_limite_reponse_interval = null, string $observations = null, array $pieces = [], ?string $statut_pec, ?string $date_premier_envoi) : void
    {
        // On recherche dans Plat'AU les détails de la consultation liée à la PEC
        $consultation = $this->getConsultation($consultation_id);

        // Définition de la DLR à envoyer
        // Correspond à la date d'instruction donnée dans la consultation si aucune date limite est donnée
        if (null === $date_limite_reponse_interval) {
            $delai_reponse            = $consultation['delaiDeReponse'];
            $type_date_limite_reponse = $consultation['nomTypeDelai']['libNom'];
            switch ($type_date_limite_reponse) {
                case 'Jours calendaires': $date_limite_reponse_interval = new DateInterval("P{$delai_reponse}D");
                break;
                case 'Mois': $date_limite_reponse_interval              = new DateInterval("P{$delai_reponse}M");
                break;
                default: throw new Exception('Type de la date de réponse attendue inconnu : '.$type_date_limite_reponse);
            }
        }

        $documents = $this->piece_service->formatDocuments($pieces, 6);

        $dt_pec_metier = 'to_export' === $statut_pec ?
            $date_premier_envoi :
            (new Datetime())->format('Y-m-d');
        $dt_limite_reponse = 'to_export' === $statut_pec ?
            DateTime::createFromFormat('Y-m-d', $date_premier_envoi)->add($date_limite_reponse_interval) :
            (new Datetime())->add($date_limite_reponse_interval);

        // Envoie de la PEC dans Plat'AU
        $this->request('post', 'pecMetier/consultations', [
            'json' => [
                [
                    'consultations' => [
                        [
                            'idConsultation' => $consultation_id,
                            'noVersion'      => $consultation['noVersion'],
                            'pecMetier'      => [
                                'dtPecMetier'            => $dt_pec_metier,
                                'dtLimiteReponse'        => $dt_limite_reponse->format('Y-m-d'),
                                'idActeurEmetteur'       => $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT'],
                                'nomStatutPecMetier'     => $est_positive ? 1 : 2,
                                'txObservations'         => (string) $observations,
                                'documents'              => $documents,
                            ],
                        ],
                    ],
                    'idDossier' => $consultation['dossier']['idDossier'],
                    'noVersion' => $consultation['dossier']['noVersion'],
                ],
            ],
        ]);
    }

    /**
     * Versement d'un avis sur une consultation.
     */
    public function versementAvis(string $consultation_id, bool $est_favorable = true, array $prescriptions = [], array $pieces = [], ?string $statut_avis, ?string $date_premier_envoi) : void
    {
        // On recherche dans Plat'AU les détails de la consultation liée
        $consultation = $this->getConsultation($consultation_id, ['nomEtatConsultation' => [3, 6]]);

        // Création du texte formulant l'avis
        $description = vsprintf('Avis Prevarisc. Prescriptions données : %s', [
            0 === \count($prescriptions) ? 'RAS' : implode(', ', array_column($prescriptions, 'libelle')),
        ]);

        $documents = $this->piece_service->formatDocuments($pieces, 9);

        $dt_avis = 'to_export' === $statut_avis ?
            $date_premier_envoi :
            (new Datetime())->format('Y-m-d');

        // Versement d'un avis
        $this->request('post', 'avis', [
            'json' => [
                [
                    'avis' => [
                        [
                            'idConsultation'     => $consultation_id,
                            'boEstTacite'        => false, // Un avis envoyé ne sera jamais tacite, il doit être considéré comme étant un avis "express" dans tous les cas
                            'nomNatureAvisRendu' => true === $est_favorable ? (0 === \count($prescriptions) ? 1 : 2) : 3, // 1 = favorable, 2 = favorable avec prescriptions, 3 = défavorable
                            'nomTypeAvis'        => 1, // Avis de type "simple"
                            'txAvis'             => $description,
                            'dtAvis'             => $dt_avis,
                            'idActeurAuteur'     => $this->getConfig()['PLATAU_ID_ACTEUR_APPELANT'],
                            'documents'          => $documents,
                        ],
                    ],
                    'idDossier' => $consultation['dossier']['idDossier'],
                    'noVersion' => $consultation['dossier']['noVersion'],
                ],
            ],
        ]);
    }
}
