<?php

namespace App\Service;

use Datetime;
use Exception;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;

class Prevarisc
{
    /**
     * Construction du service Prevarisc en lui donnant une connexion SQL.
     */
    public function __construct(Connection $db, int $user_platau_id)
    {
        // Connexion à la base de données
        $this->db = $db;

        // ID utilisateur pour lequel le service se fera passer pour ajouter des dossiers dans Prevarisc
        $this->user_platau_id = $user_platau_id;
    }

    /**
     * Récupère l'ID Utilisateur associé à Plat'AU dans Prevarisc.
     */
    public function getIdUtilisateurPlatau() : int
    {
        return $this->user_platau_id;
    }

    /**
     * Récupération dans Prevarisc d'un dossier Plat'AU.
     */
    public function recupererDossierDeConsultation(string $consultation_id) : array
    {
        $dossier = $this->db->createQueryBuilder()
            ->select('ID_DOSSIER', 'INCOMPLET_DOSSIER', 'AVIS_DOSSIER_COMMISSION')
            ->from('dossier')
            ->where('ID_PLATAU = ?')->setParameter(0, $consultation_id)->execute()->fetch();

        // Si la requête vers la base de donnée n'a rien donné, alors on lève une exception.
        if (empty($dossier)) {
            throw new Exception("La consultation n'existe pas dans Prevarisc.");
        }

        return $dossier;
    }

    /**
     * Vérifie que la consultation existe dans Prevarisc.
     */
    public function consultationExiste(string $consultation_id) : bool
    {
        try {
            $this->recupererDossierDeConsultation($consultation_id);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Vérifie si la base de données Prevarisc est disponible.
     */
    public function estDisponible() : bool
    {
        return $this->db->connect();
    }

    /**
     * Vérifie que la base de données Prevarisc est compatible avec les importations de consultations Plat'AU.
     */
    public function estCompatible() : bool
    {
        return \in_array('ID_PLATAU', array_map(function (Column $column) {
            return $column->getName();
        }, $this->db->getSchemaManager()->listTableColumns('dossier')));
    }

    /**
     * Versement d'une consultation Plat'AU dans Prevarisc.
     */
    public function importConsultation(array $consultation, array $demandeur = null, array $service_instructeur = null) : void
    {
        // On démarre une transaction SQL. Si jamais les choses se passent mal, on pourra revenir en arrière.
        $this->db->beginTransaction();

        // On essaie d'importer la consultation !
        try {
            // Préparation à l'insertion du dossier correspondant à la consultation dans Prevarisc
            $query_builder = $this->db->createQueryBuilder()->insert('dossier');

            // Le dossier est une étude, forcément
            $query_builder->setValue('TYPE_DOSSIER', '1');

            // Petite subtilité ici : par défaut dans la création d'un dossier Prevarisc, l'information de complétude est renseignée d'office.
            // Nous n'allons pas faire ça ici, et ajouter un NULL à la place, pour dire à Prevarisc que nous ne savons pas si ce dossier
            // est considéré comme complet ou pas. Cela permet à l'utilisateur de réaliser une action pour qualifier le dossier, et ainsi,
            // permettre dans une seconde action d'envoyer une PEC vers Plat'AU
            $query_builder->setValue('INCOMPLET_DOSSIER', 'NULL');

            // L'identifiant de l'utilisateur associé à Plat'AU est utilisé pour que le dossier soit créé par celui ci
            $query_builder->setValue('CREATEUR_DOSSIER', $this->getIdUtilisateurPlatau());

            // On associe le demandeur de Plat'AU
            $query_builder->setValue('DEMANDEUR_DOSSIER', $query_builder->createPositionalParameter(null !== $demandeur ? $demandeur['designationActeur'] : null));

            // On qualifie le dossier Plat'AU dans Prevarisc en renseignant les champs importants
            $query_builder->setValue('TYPESERVINSTRUC_DOSSIER', $query_builder->createPositionalParameter('servInstGrp'));
            $query_builder->setValue('SERVICEINSTRUC_DOSSIER', $query_builder->createPositionalParameter(null !== $service_instructeur ? $service_instructeur['designationActeur'] : null));

            // On place des dates importantes dans Prevarisc
            $query_builder->setValue('DATESDIS_DOSSIER', $query_builder->createPositionalParameter((new Datetime())->format('Y-m-d H:i:s')));
            $query_builder->setValue('DATEINSERT_DOSSIER', $query_builder->createPositionalParameter((new Datetime())->format('Y-m-d H:i:s')));

            // On associe la consultation Plat'AU avec le dossier créé
            $query_builder->setValue('ID_PLATAU', $query_builder->createPositionalParameter($consultation['idConsultation']));

            // Objet du dossier (c'est à dire l'objet de la consultation ainsi que le descriptif global du dossier associé)
            $query_builder->setValue('OBJET_DOSSIER', $query_builder->createPositionalParameter(vsprintf('Objet de la consultation : %s ; %s', [
                $consultation['txObjetDeLaConsultation'] ?? 'SANS OBJET',
                $consultation['dossier']['txDescriptifGlobal'],
            ])));

            // On note dans les observations du dossier des données importantes de Plat'AU (dates, type de consulation ...)
            $query_builder->setValue('OBSERVATION_DOSSIER', $query_builder->createPositionalParameter(vsprintf('Consultation PLATAU : Consultation de type %s décidée le %s et transmise au service consultable le %s. Une réponse est attendue dans %s mois.', [
                $consultation['nomTypeConsultation']['libNom'] ?? 'INCONNUE',
                $consultation['dtConsultation'] ?? 'DATE CONSULTATION INCONNUE',
                $consultation['dtEmission'] ?? 'DATE EMISSION INCONNUE',
                $consultation['delaiDeReponseEnMois'] ?? 'DELAI INCONNU',
            ])));

            // Les champs suivant doivent être mis à NULL manuellement, car aucune valeur par défaut n'est prévue dans la base de données
            $query_builder->setValue('COMMUNE_DOSSIER', 'NULL');
            $query_builder->setValue('DESCGEN_DOSSIER', 'NULL');
            $query_builder->setValue('ANOMALIE_DOSSIER', 'NULL');
            $query_builder->setValue('DESCANAL_DOSSIER', 'NULL');
            $query_builder->setValue('JUSTIFDEROG_DOSSIER', 'NULL');
            $query_builder->setValue('MESURESCOMPENS_DOSSIER', 'NULL');
            $query_builder->setValue('MESURESCOMPLE_DOSSIER', 'NULL');
            $query_builder->setValue('DESCEFF_DOSSIER', 'NULL');
            $query_builder->setValue('DATECOMM_DOSSIER', 'NULL');
            $query_builder->setValue('COORDSSI_DOSSIER', 'NULL');
            $query_builder->setValue('DATEPREF_DOSSIER', 'NULL');
            $query_builder->setValue('DATEREP_DOSSIER', 'NULL');
            $query_builder->setValue('DATEREUN_DOSSIER', 'NULL');
            $query_builder->setValue('REX_DOSSIER', 'NULL');
            $query_builder->setValue('CHARGESEC_DOSSIER', 'NULL');
            $query_builder->setValue('GRAVPRESC_DOSSIER', 'NULL');
            $query_builder->setValue('REGLEDEROG_DOSSIER', 'NULL');
            $query_builder->setValue('LIEUREUNION_DOSSIER', 'NULL');

            // On exécute la requête d'insertion dans la base de données Prevarisc
            $query_builder->execute();

            // Une fois la requête exécutée, on récupère l'identifiant du dossier créé
            $dossier_id = $this->db->lastInsertId();

            /*
            // Insertion des numéros de document d'urbanisme (PC, AT ...)
            // Il n'y a pas de numéro locaux pour l'instant dans Plat'AU
            $this->db->createQueryBuilder()->insert('dossierdocurba')->values([
                'NUM_DOCURBA' => 'NUMERO PC',
                'ID_DOSSIER'  => $dossier_id,
            ])->execute();
            */

            // On lie la nature du dossier Plat'AU avec celui de Prevarisc (avec l'aide d'une table de correspondance)
            $this->db->createQueryBuilder()->insert('dossiernature')->values([
                'ID_NATURE'  => $this->correspondanceNaturePrevarisc($consultation['dossier']['nomTypeDossier']['idNom']),
                'ID_DOSSIER' => $dossier_id,
            ])->execute();

            // On commit les changements
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Récupération des prescriptions d'un dossier.
     * Les prescriptions retournées sont sous la forme d'un tableau avec les clés :
     * - type ;
     * - libelle ;
     * - article ;
     * - texte.
     */
    public function getPrescriptions(int $dossier_id) : array
    {
        // On lance une requête pour récupérer les prescriptions + prescriptions types d'un dossier
        $prescriptions = $this->db->createQueryBuilder()
            ->select('prescriptiondossier.TYPE_PRESCRIPTION_DOSSIER', 'prescriptiondossier.LIBELLE_PRESCRIPTION_DOSSIER', 'prescriptiontype.PRESCRIPTIONTYPE_LIBELLE', 'article.LIBELLE_ARTICLE as ARTICLE', 'texte.LIBELLE_TEXTE as TEXTE', 'article_type.LIBELLE_ARTICLE as TYPE_ARTICLE', 'texte_type.LIBELLE_TEXTE as TYPE_TEXTE')
            ->from('prescriptiondossier')
            ->leftJoin('prescriptiondossier', 'prescriptiontype', 'prescriptiontype', 'prescriptiontype.ID_PRESCRIPTIONTYPE = prescriptiondossier.ID_PRESCRIPTION_TYPE')
            ->leftJoin('prescriptiondossier', 'prescriptiontypeassoc', 'prescriptiontypeassoc', 'prescriptiontypeassoc.ID_PRESCRIPTIONTYPE = prescriptiontype.ID_PRESCRIPTIONTYPE')
            ->leftJoin('prescriptiondossier', 'prescriptionarticleliste', 'article_type', 'prescriptiontypeassoc.ID_ARTICLE = article_type.ID_ARTICLE')
            ->leftJoin('prescriptiondossier', 'prescriptiontexteliste', 'texte_type', 'prescriptiontypeassoc.ID_TEXTE = texte_type.ID_TEXTE')
            ->leftJoin('prescriptiondossier', 'prescriptiondossierassoc', 'prescriptiondossierassoc', 'prescriptiondossier.ID_PRESCRIPTION_DOSSIER = prescriptiondossierassoc.ID_PRESCRIPTION_DOSSIER')
            ->leftJoin('prescriptiondossier', 'prescriptionarticleliste', 'article', 'prescriptiondossierassoc.ID_ARTICLE = article.ID_ARTICLE')
            ->leftJoin('prescriptiondossier', 'prescriptiontexteliste', 'texte', 'prescriptiondossierassoc.ID_TEXTE = texte.ID_TEXTE')
            ->where('prescriptiondossier.ID_DOSSIER = ?')->setParameter(0, $dossier_id)->execute()->fetchAll();

        // On parse les prescriptions
        $prescriptions = array_map(function ($prescription) {
            return [
                'type'    => $prescription['TYPE_PRESCRIPTION_DOSSIER'], // 1 = Rappels Réglementaires, 2 = Exploitation, 3 = Recommandations
                'libelle' => $prescription['LIBELLE_PRESCRIPTION_DOSSIER'] ?? $prescription['PRESCRIPTIONTYPE_LIBELLE'],
                'article' => $prescription['ARTICLE'] ?? $prescription['TYPE_ARTICLE'],
                'texte'   => $prescription['TEXTE'] ?? $prescription['TYPE_TEXTE'],
            ];
        }, $prescriptions);

        return $prescriptions;
    }

    /**
     * Importer des pièces jointes dans un dossier.
     */
    public function creerPieceJointe(int $dossier_id, string $filename, string $extension, string $description = '') : void
    {
        $query_builder = $this->db->createQueryBuilder();
        $query_builder->insert('piecejointe')->values([
            'NOM_PIECEJOINTE'         => $query_builder->createPositionalParameter($filename),
            'EXTENSION_PIECEJOINTE'   => $query_builder->createPositionalParameter($extension),
            'DATE_PIECEJOINTE'        => $query_builder->createPositionalParameter((new Datetime())->format('Y-m-d')),
            'DESCRIPTION_PIECEJOINTE' => $query_builder->createPositionalParameter($description),
        ])->execute();
    }

    /**
     * Correspondance entre une nature de dossier PlatAU et Prevarisc.
     * On lui donne un ID PlatAU et il nous ressort un ID Prevarisc.
     * Si l'ID Prevarisc correspondant n'existe pas, la fonction lève une exception.
     */
    public static function correspondanceNaturePrevarisc(int $platau_nature_id) : int
    {
        switch ($platau_nature_id) {
            case 1: return 62; // Certificat d’urbanisme d’information (CUa)
            case 2: return 62; // Certificat d’urbanisme opérationnel (CUb)
            case 3: return 30; // Déclaration préalable (DP)
            case 4: return 1; // Permis de construire (PC)
            case 5: return 14; // Permis d’aménager (PA)
            case 6: return 15; // Permis de démolir (PD)
            case 7: throw new Exception('Nature Demande de transfert (DT) non supportée');
            case 8: throw new Exception('Nature Dossier d’infraction (DI) non supportée');
        }

        throw new Exception('Nature inconnue');
    }
}
