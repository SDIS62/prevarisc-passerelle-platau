<?php

namespace App\Command;

use App\Service\PlatauPiece;
use App\ValueObjects\Auteur;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ExportPEC extends Command
{
    private PrevariscService $prevarisc_service;
    private PlatauConsultationService $consultation_service;
    private PlatauPiece $piece_service;

    /**
     * Initialisation de la commande.
     */
    public function __construct(PrevariscService $prevarisc_service, PlatauConsultationService $consultation_service, PlatauPiece $piece_service)
    {
        $this->prevarisc_service    = $prevarisc_service;
        $this->consultation_service = $consultation_service;
        $this->piece_service        = $piece_service;
        parent::__construct();
    }

    /**
     * Configuration de la commande.
     */
    protected function configure()
    {
        $this->setName('export-pec')
            ->setDescription("Exporte des Prises En Compte métier sur Plat'AU.")
            ->addOption('consultation-id', null, InputOption::VALUE_OPTIONAL, 'Consultation concernée')
            ->addOption('delai-reponse', null, InputOption::VALUE_OPTIONAL, 'DLR différente de celle inscrite dans la PEC (en nombre de jours)')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Si l'utilisateur demande de traiter une consultation en particulier, on s'occupe de celle là.
        // Sinon on récupère dans Plat'AU l'ensemble des consultations en attente de PEC (c'est à dire avec un état "Non Traitée")
        if ($input->getOption('consultation-id')) {
            $output->writeln('Récupération de la consultation concernée ...');
            $consultations_en_attente_de_pec = [$this->consultation_service->getConsultation($input->getOption('consultation-id'))];
        } else {
            $output->writeln('Recherche de consultations en attente de prise en compte métier et celle refusées ...');
            $consultations_en_attente_de_pec = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => [1, 2]]);
        }

        // Si une DLR personnalisée est demandée par l'utilisateur
        $delai_reponse = null;
        if ($input->getOption('delai-reponse')) {
            $delai_reponse = new \DateInterval("P{$input->getOption('delai-reponse')}D");
        }

        // Pour chaque consultation trouvée, on va chercher dans Prevarisc si la complétion (ou non) du dossier a été indiquée.
        foreach ($consultations_en_attente_de_pec as $consultation) {
            // Récupération de l'ID de la consultation
            $consultation_id = $consultation['idConsultation'];

            // On essaie d'envoyer la PEC
            try {
                $pieces = [];

                // Récupération du dossier lié à la consultation
                $dossier = $this->prevarisc_service->recupererDossierDeConsultation($consultation_id);
                $auteur  = $this->prevarisc_service->recupererDossierAuteur($dossier['ID_DOSSIER']);

                if (2 === $consultation['nomEtatConsultation']['idNom'] && !\in_array($dossier['STATUT_PEC'], ['to_export', 'in_error'])) {
                    continue;
                }

                // On recherche les pièces jointes en attente d'envoi vers Plat'AU associées au dossier Prevarisc
                if ($this->piece_service->getSyncplicity()) {
                    $pieces_to_export = $this->prevarisc_service->recupererPiecesAvecStatut($dossier['ID_DOSSIER'], 'to_be_exported');
                    foreach ($pieces_to_export as $piece_jointe) {
                        $filename = $piece_jointe['NOM_PIECEJOINTE'].$piece_jointe['EXTENSION_PIECEJOINTE'];
                        $contents = $this->prevarisc_service->recupererFichierPhysique($piece_jointe['ID_PIECEJOINTE'], $piece_jointe['EXTENSION_PIECEJOINTE']);

                        try {
                            $pieces[] = $this->piece_service->uploadDocument($filename, $contents, 47); // Type document 47 = Document lié à une prise en compte métier
                            $this->prevarisc_service->changerStatutPiece($piece_jointe['ID_PIECEJOINTE'], 'exported');
                        } catch (\Exception $e) {
                            $this->prevarisc_service->changerStatutPiece($piece_jointe['ID_PIECEJOINTE'], 'on_error');
                        }
                    }
                }
                $documentsManquants = $this->prevarisc_service->recupererDocumentsManquants($dossier['ID_DOSSIER']);

                // Si le dossier est déclaré incomplet, on envoie une PEC négative
                if ('1' === (string) $dossier['INCOMPLET_DOSSIER']) {
                    $output->writeln("Notification de la Prise En Compte Négative de la consultation $consultation_id au service instructeur ...");

                    // Si cela concerne un premier envoi de PEC alors on place la date de la PEC Prevarisc, sinon la date du lancement de la commande
                    $this->consultation_service->envoiPEC(
                        $consultation_id,
                        false,
                        $delai_reponse,
                        $documentsManquants,
                        $pieces,
                        'to_export' === $dossier['STATUT_PEC'] ? \DateTime::createFromFormat('Y-m-d', $dossier['DATE_PEC']) : null,
                        new Auteur($auteur['PRENOM_UTILISATEURINFORMATIONS'], $auteur['NOM_UTILISATEURINFORMATIONS'], $auteur['MAIL_UTILISATEURINFORMATIONS'], $auteur['TELFIXE_UTILISATEURINFORMATIONS'], $auteur['TELPORTABLE_UTILISATEURINFORMATIONS']),
                    );
                    $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'PEC', 'taken_into_account')->set('DATE_PEC', ':date_pec')->setParameter('date_pec', date('Y-m-d'))->executeStatement();
                    $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'AVIS', 'in_progress')->executeStatement();

                    $output->writeln('Notification de la Prise En Compte Négative envoyée !');
                } elseif ('0' === (string) $dossier['INCOMPLET_DOSSIER']) {
                    $output->writeln("Notification de la Prise En Compte Positive de la consultation $consultation_id au service instructeur ...");

                    // Si cela concerne un premier envoi de PEC alors on place la date de la PEC Prevarisc, sinon la date du lancement de la commande
                    $this->consultation_service->envoiPEC(
                        $consultation_id,
                        true,
                        $delai_reponse,
                        null,
                        $pieces,
                        'to_export' === $dossier['STATUT_PEC'] ? \DateTime::createFromFormat('Y-m-d', $dossier['DATE_PEC']) : new \DateTime(),
                        new Auteur($auteur['PRENOM_UTILISATEURINFORMATIONS'], $auteur['NOM_UTILISATEURINFORMATIONS'], $auteur['MAIL_UTILISATEURINFORMATIONS'], $auteur['TELFIXE_UTILISATEURINFORMATIONS'], $auteur['TELPORTABLE_UTILISATEURINFORMATIONS']),
                    );
                    $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'PEC', 'taken_into_account')->set('DATE_PEC', ':date_pec')->setParameter('date_pec', date('Y-m-d'))->executeStatement();
                    $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'AVIS', 'in_progress')->executeStatement();

                    $output->writeln('Notification de la Prise En Compte Positive envoyée !');
                } else {
                    $output->writeln("Impossible d'envoyer une PEC pour la consultation $consultation_id pour le moment (en attente de l'indication de complétude du dossier dans Prevarisc) ...");
                }
            } catch (\Exception $e) {
                // On passe les pièces jointes en attente de versement
                foreach ($pieces as $piece) {
                    if ('on_error' !== $piece['NOM_STATUT']) {
                        $this->prevarisc_service->changerStatutPiece($piece['ID_PIECEJOINTE'], 'to_be_exported');
                    }
                }

                // On passe la PEC en erreur d'envoi
                $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'PEC', 'in_error')->executeStatement();

                $output->writeln("Problème lors du traitement de la consultation : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
