<?php

namespace App\Command;

use App\Service\PlatauPiece;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ExportAvis extends Command
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
        $this->setName('export-avis')
            ->setDescription("Exporte un avis Prevarisc sur Plat'AU.")
            ->addOption('consultation-id', null, InputOption::VALUE_OPTIONAL, 'Consultation concernée')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Si l'utilisateur demande de traiter une consultation en particulier, on s'occupe de celle là.
        // Sinon, l'utilisateur demande de traiter les consultations en attente d'avis
        // Sinon on récupère dans Plat'AU l'ensemble des consultations en attente d'avis (c'est à dire avec un état "Prise en compte - en cours de traitement") et celle déjà traitées
        if ($input->getOption('consultation-id')) {
            $output->writeln('Récupération de la consultation concernée ...');
            $consultations_en_attente_davis = [$this->consultation_service->getConsultation($input->getOption('consultation-id'))];
        } else {
            $output->writeln('Recherche de toutes les consultations en attente d\'avis ou traitées ...');
            $consultations_en_attente_davis = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => [3, 6]]);
        }

        // Si il n'existe pas de consultations en attente d'avis, on arrête le travail ici
        if (empty($consultations_en_attente_davis)) {
            $output->writeln('Pas de consultations en attente d\'avis.');

            return Command::SUCCESS;
        }

        // Pour chaque consultation trouvée, on va chercher dans Prevarisc si un avis existe.
        foreach ($consultations_en_attente_davis as $consultation) {
            // Récupération de l'ID de la consultation
            $consultation_id = $consultation['idConsultation'];

            // On essaie d'envoyer l'avis sur Plat'AU
            try {
                // Récupération du dossier dans Prevarisc
                $dossier = $this->prevarisc_service->recupererDossierDeConsultation($consultation_id);

                if (6 === $consultation['nomEtatConsultation']['idNom'] && !\in_array($dossier['STATUT_AVIS'], ['to_export', 'in_error'])) {
                    continue;
                }

                // On recherche les prescriptions associées au dossier Prevarisc
                $prescriptions = $this->prevarisc_service->getPrescriptions($dossier['ID_DOSSIER']);

                // On recherche les pièces jointes en attente d'envoi vers Plat'AU associées au dossier Prevarisc
                $pieces = [];
                if ($this->piece_service->getSyncplicity()) {
                    $pieces = array_map(function ($piece_jointe) {
                        $filename = $piece_jointe['NOM_PIECEJOINTE'].$piece_jointe['EXTENSION_PIECEJOINTE'];
                        $contents = $this->prevarisc_service->recupererFichierPhysique($piece_jointe['ID_PIECEJOINTE'], $piece_jointe['EXTENSION_PIECEJOINTE']);

                        return $this->piece_service->uploadDocument($filename, $contents, 9); // Type de document 9 = Document lié à un avis
                    }, $this->prevarisc_service->recupererPiecesAvecStatut($dossier['ID_DOSSIER'], 'to_be_exported'));
                }

                // On verse l'avis de commission Prevarisc (défavorable ou favorable à l'étude) dans Plat'AU
                if ('1' === (string) $dossier['AVIS_DOSSIER_COMMISSION'] || '2' === (string) $dossier['AVIS_DOSSIER_COMMISSION']) {
                    // On verse l'avis de commission dans Plat'AU
                    // Pour rappel, un avis de commission à 1 = favorable, 2 = défavorable.
                    $est_favorable = '1' === (string) $dossier['AVIS_DOSSIER_COMMISSION'];
                    $output->writeln("Versement d'un avis ".($est_favorable ? 'favorable' : 'défavorable')." pour la consultation $consultation_id au service instructeur ...");
                    // Si cela concerne un premier envoi d'avis alors on place la date de l'avis Prevarisc, sinon la date du lancement de la commande
                    $this->consultation_service->versementAvis($consultation_id, $est_favorable, $prescriptions, $pieces, 'to_export' === $dossier['STATUT_AVIS'] ? \DateTime::createFromFormat('Y-m-d', $dossier['DATE_AVIS']) : new \DateTime());
                    $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'AVIS', 'treated')->setValue('DATE_AVIS', ':date_avis')->setParameter('date_avis', date('Y-m-d'))->executeStatement();
                    $output->writeln('Avis envoyé !');
                } else {
                    $output->writeln("Impossible d'envoyer un avis pour la consultation $consultation_id pour le moment (en attente de l'avis de commission dans Prevarisc) ...");
                }
            } catch (\Exception $e) {
                // On passe toutes les pièces en attente de versement
                foreach ($pieces as $piece) {
                    if ('on_error' !== $piece['NOM_STATUT']) {
                        $this->prevarisc_service->changerStatutPiece($piece['ID_PIECEJOINTE'], 'to_be_exported');
                    }
                }

                // On passe la consultation en erreur dans Prevarisc
                $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'AVIS', 'in_error')->executeStatement();

                $output->writeln("Problème lors du versement de l'avis : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
