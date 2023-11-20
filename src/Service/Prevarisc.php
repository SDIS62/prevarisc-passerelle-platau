<?php

namespace App\Service;

use Exception;
use League\Flysystem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Query\QueryBuilder;

class Prevarisc
{
    private Connection $db;
    private int $user_platau_id;
    private Flysystem\Filesystem $filesystem;

    public const NECESSARY_TABLES = [
        'piecejointestatut',
        'platauconsultation',
    ];

    /**
     * Construction du service Prevarisc en lui donnant une connexion SQL.
     */
    public function __construct(Connection $db, int $user_platau_id, Flysystem\Filesystem $filesystem)
    {
        // Connexion à la base de données
        $this->db = $db;

        // ID utilisateur pour lequel le service se fera passer pour ajouter des dossiers dans Prevarisc
        $this->user_platau_id = $user_platau_id;

        // Composant filesystem permettant d'interargir avec les pièces jointes de Prevarisc
        $this->filesystem = $filesystem;
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
     *
     * @throws \Exception
     */
    public function recupererDossierDeConsultation(string $consultation_id) : array
    {
        $results = $this->db->createQueryBuilder()
            ->select('ID_DOSSIER', 'INCOMPLET_DOSSIER', 'AVIS_DOSSIER_COMMISSION', 'STATUT_PEC', 'DATE_PEC', 'STATUT_AVIS', 'DATE_AVIS')
            ->from('dossier')
            ->leftJoin('dossier', 'platauconsultation', 'platauconsultation', 'dossier.ID_PLATAU = platauconsultation.ID_PLATAU')
            ->where('dossier.ID_PLATAU = ?')
            ->setParameter(0, $consultation_id)
            ->executeQuery();

        $dossier = $results->fetchAssociative();

        // Si la requête vers la base de donnée n'a rien donné, alors on lève une exception.
        if (empty($dossier)) {
            throw new \Exception("La consultation $consultation_id n'existe pas dans Prevarisc.");
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
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Vérifie si la base de données Prevarisc est disponible.
     */
    public function estDisponible() : bool
    {
        /* @psalm-suppress InternalMethod */
        return $this->db->connect();
    }

    /**
     * Vérifie que la base de données Prevarisc est compatible avec les importations de consultations Plat'AU.
     */
    public function estCompatible() : bool
    {
        return
            // Colonne 'ID_PLATAU' dans la table 'dossiers'

                \in_array('ID_PLATAU', array_map(function (Column $column) {
                    return $column->getName();
                }, $this->db->createSchemaManager()->listTableColumns('dossier')))
             &&
            // Colonne 'STATUT_PEC' dans la table 'dossiers'

                \in_array('STATUT_PEC', array_map(function (Column $column) {
                    return $column->getName();
                }, $this->db->createSchemaManager()->listTableColumns('dossier')))
             &&
            // Colonne 'DATE_PEC' dans la table 'dossiers'

                \in_array('DATE_PEC', array_map(function (Column $column) {
                    return $column->getName();
                }, $this->db->createSchemaManager()->listTableColumns('dossier')))
             &&
            // Colonne 'STATUT_AVIS' dans la table 'dossiers'

                \in_array('STATUT_AVIS', array_map(function (Column $column) {
                    return $column->getName();
                }, $this->db->createSchemaManager()->listTableColumns('dossier')))
             &&
            // Colonne 'DATE_AVIS' dans la table 'dossiers'

                \in_array('DATE_AVIS', array_map(function (Column $column) {
                    return $column->getName();
                }, $this->db->createSchemaManager()->listTableColumns('dossier')))
             &&
            // Présence de la table 'piecejointestatut'
            \in_array('piecejointestatut', $this->db->createSchemaManager()->listTableNames()) &&
            // Présence de la table 'platauconsultation'
            \in_array('platauconsultation', $this->db->createSchemaManager()->listTableNames())
        ;
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
            $query_builder->setValue('CREATEUR_DOSSIER', (string) $this->getIdUtilisateurPlatau());

            // On associe le demandeur de Plat'AU
            $query_builder->setValue('DEMANDEUR_DOSSIER', $query_builder->createPositionalParameter(null !== $demandeur ? $demandeur['designationActeur'] : null));

            // On qualifie le dossier Plat'AU dans Prevarisc en renseignant les champs importants
            $query_builder->setValue('TYPESERVINSTRUC_DOSSIER', $query_builder->createPositionalParameter('servInstGrp'));
            $query_builder->setValue('SERVICEINSTRUC_DOSSIER', $query_builder->createPositionalParameter(null !== $service_instructeur ? $service_instructeur['designationActeur'] : null));

            // On place des dates importantes dans Prevarisc
            $query_builder->setValue('DATESDIS_DOSSIER', $query_builder->createPositionalParameter((new \DateTime())->format('Y-m-d H:i:s')));
            $query_builder->setValue('DATEINSERT_DOSSIER', $query_builder->createPositionalParameter((new \DateTime())->format('Y-m-d H:i:s')));

            // On associe la consultation Plat'AU avec le dossier créé
            $query_builder->setValue('ID_PLATAU', $query_builder->createPositionalParameter($consultation['idConsultation']));

            // Objet du dossier (c'est à dire l'objet de la consultation ainsi que le descriptif global du dossier associé)
            $query_builder->setValue('OBJET_DOSSIER', $query_builder->createPositionalParameter(vsprintf('Objet de la consultation : %s ; %s', [
                $consultation['txObjetDeLaConsultation'] ?? 'SANS OBJET',
                $consultation['dossier']['txDescriptifGlobal'],
            ])));

            // On note dans les observations du dossier des données importantes de Plat'AU (dates, type de consulation ...)
            $query_builder->setValue('OBSERVATION_DOSSIER', $query_builder->createPositionalParameter(vsprintf('Consultation PLATAU : Consultation de type %s décidée le %s et transmise au service consultable le %s. Une réponse est attendue dans %s %s. (ID PLATAU DOSSIER : %s)', [
                $consultation['nomTypeConsultation']['libNom'] ?? 'INCONNUE',
                $consultation['dtConsultation'] ?? 'DATE CONSULTATION INCONNUE',
                $consultation['dtEmission'] ?? 'DATE EMISSION INCONNUE',
                $consultation['delaiDeReponse'] ?? 'DELAI INCONNU',
                $consultation['nomTypeDelai']['libNom'] ?? '',
                $consultation['dossier']['idDossier'] ?? 'ID INCONNU',
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
            $query_builder->executeStatement();

            // Une fois la requête exécutée, on récupère l'identifiant du dossier créé
            $dossier_id = $this->db->lastInsertId();

            // Insertion des numéros de document d'urbanisme (PC, AT ...)
            if (null !== $consultation['dossier']['noLocal']) {
                $num_doc_urba = $consultation['dossier']['noLocal'];

                if (null !== $consultation['dossier']['suffixeNoLocal']) {
                    $num_doc_urba .= $consultation['dossier']['suffixeNoLocal'];
                }

                $query_builder_docurba = $this->db->createQueryBuilder()->insert('dossierdocurba');
                $query_builder_docurba->values([
                    'NUM_DOCURBA' => $query_builder_docurba->createPositionalParameter($num_doc_urba),
                    'ID_DOSSIER'  => $dossier_id,
                ])->executeStatement();
            }

            // On lie la nature du dossier Plat'AU avec celui de Prevarisc (avec l'aide d'une table de correspondance)
            $this->db->createQueryBuilder()->insert('dossiernature')->values([
                'ID_NATURE'  => $this->correspondanceNaturePrevarisc($consultation['dossier']['nomTypeDossier']['idNom']),
                'ID_DOSSIER' => $dossier_id,
            ])->executeStatement();

            // On commit les changements
            $this->db->commit();
        } catch (\Exception $e) {
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
        $results = $this->db->createQueryBuilder()
            ->select('prescriptiondossier.TYPE_PRESCRIPTION_DOSSIER', 'prescriptiondossier.LIBELLE_PRESCRIPTION_DOSSIER', 'prescriptiontype.PRESCRIPTIONTYPE_LIBELLE', 'article.LIBELLE_ARTICLE as ARTICLE', 'texte.LIBELLE_TEXTE as TEXTE', 'article_type.LIBELLE_ARTICLE as TYPE_ARTICLE', 'texte_type.LIBELLE_TEXTE as TYPE_TEXTE')
            ->from('prescriptiondossier')
            ->leftJoin('prescriptiondossier', 'prescriptiontype', 'prescriptiontype', 'prescriptiontype.ID_PRESCRIPTIONTYPE = prescriptiondossier.ID_PRESCRIPTION_TYPE')
            ->leftJoin('prescriptiondossier', 'prescriptiontypeassoc', 'prescriptiontypeassoc', 'prescriptiontypeassoc.ID_PRESCRIPTIONTYPE = prescriptiontype.ID_PRESCRIPTIONTYPE')
            ->leftJoin('prescriptiondossier', 'prescriptionarticleliste', 'article_type', 'prescriptiontypeassoc.ID_ARTICLE = article_type.ID_ARTICLE')
            ->leftJoin('prescriptiondossier', 'prescriptiontexteliste', 'texte_type', 'prescriptiontypeassoc.ID_TEXTE = texte_type.ID_TEXTE')
            ->leftJoin('prescriptiondossier', 'prescriptiondossierassoc', 'prescriptiondossierassoc', 'prescriptiondossier.ID_PRESCRIPTION_DOSSIER = prescriptiondossierassoc.ID_PRESCRIPTION_DOSSIER')
            ->leftJoin('prescriptiondossier', 'prescriptionarticleliste', 'article', 'prescriptiondossierassoc.ID_ARTICLE = article.ID_ARTICLE')
            ->leftJoin('prescriptiondossier', 'prescriptiontexteliste', 'texte', 'prescriptiondossierassoc.ID_TEXTE = texte.ID_TEXTE')
            ->where('prescriptiondossier.ID_DOSSIER = ?')->setParameter(0, $dossier_id)->executeQuery();

        $prescriptions = $results->fetchAllAssociative();

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
     * Vérifie que la pièce jointe existe dans Prevarisc.
     */
    public function pieceJointeExisteDansDossier(int $dossier_id, string $filename) : bool
    {
        $query_builder = $this->db->createQueryBuilder();

        // Recherche de la pièce jointe dans un dossier Prevarisc
        $result = $query_builder
            ->select('piecejointe.ID_PIECEJOINTE')
            ->from('piecejointe')
            ->leftJoin('piecejointe', 'dossierpj', 'dossierpj', 'piecejointe.ID_PIECEJOINTE = dossierpj.ID_PIECEJOINTE')
            ->where(
                $query_builder->expr()->and(
                    $query_builder->expr()->eq('piecejointe.NOM_PIECEJOINTE', '?'),
                    $query_builder->expr()->eq('dossierpj.ID_DOSSIER', '?')
                )
            )
            ->setParameter(0, $filename)
            ->setParameter(1, $dossier_id)
            ->executeQuery();

        $piece_jointe = $result->fetchAssociative();

        \assert(\is_array($piece_jointe));

        return !empty($piece_jointe);
    }

    /**
     * Importer des pièces jointes dans un dossier.
     */
    public function creerPieceJointe(int $dossier_id, array $piece, string $extension, string $file_contents) : void
    {
        // Génération du nom du fichier
        $filename = vsprintf('PLATAU-%s-%s-v%d', [$piece['idPiece'], $piece['noPiece'], $piece['noVersion']]);

        // Si le fichier existe déjà, on ne l'importe pas
        if ($this->pieceJointeExisteDansDossier($dossier_id, $filename)) {
            return;
        }

        // Génération de la description
        $description = vsprintf('%s v%s (Pièce %s avec un état %s. Elle a été déposée le %s et produite le %s.)', [
            (string) $piece['nomTypePiece']['libNom'].' '.(string) $piece['libAutreTypePiece'],
            $piece['noVersion'],
            $piece['nomNaturePiece']['libNom'],
            $piece['nomEtatPiece']['libNom'],
            (new \DateTime($piece['dtDepot']))->format('d/m/Y à H:i'),
            (new \DateTime($piece['dtProduction']))->format('d/m/Y à H:i'),
        ]);

        // Ajout d'un point avant l'extension
        $extension = '.'.$extension;

        // On démarre une transaction SQL. Si jamais les choses se passent mal, on pourra revenir en arrière.
        $this->db->beginTransaction();

        try {
            // Création de l'item pièce jointe
            $query_builder = $this->db->createQueryBuilder();
            $query_builder->insert('piecejointe')->values([
                'NOM_PIECEJOINTE'         => $query_builder->createPositionalParameter($filename),
                'EXTENSION_PIECEJOINTE'   => $query_builder->createPositionalParameter($extension),
                'DATE_PIECEJOINTE'        => $query_builder->createPositionalParameter((new \DateTime())->format('Y-m-d')),
                'DESCRIPTION_PIECEJOINTE' => $query_builder->createPositionalParameter($description),
            ])->executeStatement();

            $piece_jointe_id = (string) $this->db->lastInsertId();

            // Création de la liaison avec la pièce jointe et le dossier
            $this->db->createQueryBuilder()->insert('dossierpj')->values([
                'ID_PIECEJOINTE' => $piece_jointe_id,
                'ID_DOSSIER'     => $dossier_id,
                'PJ_COMMISSION'  => 0,
            ])->executeStatement();

            // Stockage de la pièce jointe
            $this->filesystem->write($piece_jointe_id.$extension, $file_contents);

            // On commit les changements
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Récupère les pièces jointes avec un statut d'envoi vers Plat'AU spécifique.
     * Le statut peut être : on_error ; not_exported ; to_be_exported ; exported.
     */
    public function recupererPiecesAvecStatut(int $id_dossier, string $status) : array
    {
        $query_builder = $this->db->createQueryBuilder();

        $query = $query_builder
            ->select('pj.ID_PIECEJOINTE', 'pj.EXTENSION_PIECEJOINTE', 'pj.NOM_PIECEJOINTE', 'd.ID_PLATAU', 'pjs.NOM_STATUT')
            ->from('piecejointe', 'pj')
            ->leftJoin('pj', 'piecejointestatut', 'pjs', 'pjs.ID_PIECEJOINTESTATUT = pj.ID_PIECEJOINTESTATUT')
            ->join('pj', 'dossierpj', 'dpj', 'dpj.ID_PIECEJOINTE = pj.ID_PIECEJOINTE')
            ->join('dpj', 'dossier', 'd', 'd.ID_DOSSIER = dpj.ID_DOSSIER')
            ->where(
                $query_builder->expr()->and(
                    $query_builder->expr()->eq('d.ID_DOSSIER', '?'),
                    $query_builder->expr()->eq('pjs.NOM_STATUT', '?')
                )
            )
            ->setParameter(0, $id_dossier)
            ->setParameter(1, $status)
            ->executeQuery()
        ;

        return $query->fetchAllAssociative();
    }

    /**
     * Récupère la pièce jointe sur le serveur.
     */
    public function recupererFichierPhysique(int $piece_jointe_id, string $piece_jointe_extension) : string
    {
        return $this->filesystem->read("{$piece_jointe_id}{$piece_jointe_extension}");
    }

    /**
     * Modifie le statut d'envoi vers Plat'AU de la pièce.
     * Le statut peut être : on_error ; not_exported ; to_be_exported ; exported.
     */
    public function changerStatutPiece(int $piece_jointe_id, string $statut) : void
    {
        $query_builder = $this->db->createQueryBuilder();

        /** @var string|false $id_statut */
        $id_statut = $query_builder
            ->select('ID_PIECEJOINTESTATUT')
            ->from('piecejointestatut')
            ->where(
                $query_builder->expr()->eq('NOM_STATUT', '?')
            )
            ->setParameter(0, $statut)
            ->executeQuery()
            ->fetchOne()
        ;

        if (false === $id_statut) {
            throw new \Exception("Statut $statut inconnu");
        }

        $query_builder
            ->update('piecejointe', 'pj')
            ->set('ID_PIECEJOINTESTATUT', $id_statut)
            ->where(
                $query_builder->expr()->eq('ID_PIECEJOINTE', '?')
            )
            ->setParameter(0, $piece_jointe_id)
            ->executeStatement()
        ;
    }

    /**
     * Mise à jour des métadonnées de la consultation.
     * Objet métier : AVIS ; Statut : unknown ; in_progress ; treated ; to_export ; in_error
     * Objet métier : PEC ; Statut : unknown ; awaiting ; taken_into_account ; to_export ; in_error.
     */
    public function setMetadonneesEnvoi(string $consultation_id, string $objet_metier, string $statut) : QueryBuilder
    {
        $query_builder_select = $this->db->createQueryBuilder();
        $query_builder        = $this->db->createQueryBuilder();

        $consultation_metadonnees = $query_builder_select
            ->select('ID_PLATAU')
            ->from('platauconsultation')
            ->where(
                $query_builder_select->expr()->eq('ID_PLATAU', ':id')
            )
            ->setParameter('id', $consultation_id)
            ->executeQuery()
        ;

        if (false === $consultation_metadonnees->fetchOne()) {
            $query_builder
                ->insert('platauconsultation')
                ->setValue('ID_PLATAU', ':id')
                ->setValue(sprintf('STATUT_%s', $objet_metier), ':statut')
                ->setParameter('id', $consultation_id)
                ->setParameter('statut', $statut)
            ;

            return $query_builder;
        }

        /*
            Les statuts AVIS :
            ------------------
            INCONNU = 'unknown';
            EN_COURS = 'in_progress';
            TRAITE = 'treated';
            A_RENVOYER = 'to_export';
            EN_ERREUR = 'in_error';

            Les statits PEC :
            -----------------
            INCONNU = 'unknown';
            EN_ATTENTE = 'awaiting';
            PRISE_EN_COMPTE = 'taken_into_account';
            A_RENVOYER = 'to_export';
            EN_ERREUR = 'in_error';
        */

        $query_builder
            ->update('platauconsultation')
            ->set(sprintf('STATUT_%s', $objet_metier), ':statut')
            ->where('ID_PLATAU = :id')
            ->setParameter('statut', $statut)
            ->setParameter('id', $consultation_id)
        ;

        return $query_builder;
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
            case 7: throw new \Exception('Nature Demande de transfert (DT) non supportée');
            case 8: throw new \Exception('Nature Dossier d’infraction (DI) non supportée');
        }

        throw new \Exception('Nature inconnue');
    }
}
