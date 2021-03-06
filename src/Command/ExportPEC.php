<?php

namespace App\Command;

use Exception;
use DateInterval;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ExportPEC extends Command
{
    /**
     * Initialisation de la commande.
     */
    public function __construct(PrevariscService $prevarisc_service, PlatauConsultationService $consultation_service)
    {
        $this->prevarisc_service    = $prevarisc_service;
        $this->consultation_service = $consultation_service;
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
            $output->writeln('Recherche de consultations en attente de prise en compte métier ...');
            $consultations_en_attente_de_pec = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => 1]);
        }

        // Si une DLR personnalisée est demandée par l'utilisateur
        $delai_reponse = null;
        if ($input->getOption('delai-reponse')) {
            $delai_reponse = new DateInterval("P{$input->getOption('delai-reponse')}D");
        }

        // Pour chaque consultation trouvée, on va chercher dans Prevarisc si la complétion (ou non) du dossier a été indiquée.
        foreach ($consultations_en_attente_de_pec as $consultation) {
            // Récupération de l'ID de la consultation
            $consultation_id = $consultation['idConsultation'];

            // On essaie d'envoyer la PEC
            try {
                // Récupération du dossier lié à la consultation
                $dossier = $this->prevarisc_service->recupererDossierDeConsultation($consultation_id);

                // Si le dossier est déclaré incomplet, on envoie une PEC négative
                if ('1' === $dossier['INCOMPLET_DOSSIER']) {
                    $output->writeln("Notification de la Prise En Compte Négative de la consultation $consultation_id au service instructeur ...");
                    $this->consultation_service->envoiPEC($consultation_id, false, $delai_reponse);
                    $output->writeln('Notification de la Prise En Compte Négative envoyée !');
                } elseif ('0' === $dossier['INCOMPLET_DOSSIER']) {
                    $output->writeln("Notification de la Prise En Compte Positive de la consultation $consultation_id au service instructeur ...");
                    $this->consultation_service->envoiPEC($consultation_id, true, $delai_reponse);
                    $output->writeln('Notification de la Prise En Compte Positive envoyée !');
                } else {
                    $output->writeln("Impossible d'envoyer une PEC pour la consultation $consultation_id pour le moment (en attente de l'indication de complétude du dossier dans Prevarisc) ...");
                }
            } catch (Exception $e) {
                $output->writeln("Problème lors du traitement de la consultation : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
