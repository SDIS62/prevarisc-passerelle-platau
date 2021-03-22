<?php

namespace App\Command;

use Exception;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ExportAvis extends Command
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
        $this->setName('export-avis')
            ->setDescription("Exporte un avis Prevarisc sur Plat'AU.")
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // On récupère dans Plat'AU l'ensemble des consultations en attente d'avis (c'est à dire avec un état "Prise en compte avec une intention de prescrire")
        $output->writeln('Recherche de consultations en attente d\'avis ...');
        $consultations_en_attente_davis = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => 4]);

        // Pour chaque consultation trouvée, on va chercher dans Prevarisc si un avis existe.
        foreach ($consultations_en_attente_davis as $consultation) {
            // Récupération de l'ID de la consultation
            $consultation_id = $consultation['idConsultation'];

            // On essaie d'envoyer l'avis sur Plat'AU
            try {
                // Récupération du dossier dans Prevarisc
                $dossier = $this->prevarisc_service->recupererDossierDeConsultation($consultation_id);

                // On recherche les prescriptions associées au dossier Prevarisc
                $prescriptions = $this->prevarisc_service->getPrescriptions($dossier['ID_DOSSIER']);

                // On verse l'avis de commission Prevarisc (défavorable ou favorable à l'étude) dans Plat'AU
                if ('1' === $dossier['AVIS_DOSSIER_COMMISSION'] || '2' === $dossier['AVIS_DOSSIER_COMMISSION']) {
                    // On verse l'avis de commission dans Plat'AU
                    // Pour rappel, un avis de commission à 1 = favorable, 2 = défavorable.
                    $output->writeln("Versement d'un avis ".('1' === $dossier['AVIS_DOSSIER_COMMISSION'] ? 'favorable' : 'défavorable')." pour la consultation $consultation_id au service instructeur ...");
                    $this->consultation_service->versementAvis($consultation_id, '1' === $dossier['AVIS_DOSSIER_COMMISSION'], $prescriptions);
                    $output->writeln('Avis envoyé !');
                } else {
                    $output->writeln("Impossible d'envoyer un avis pour la consultation $consultation_id pour le moment (en attente de l'avis de commission dans Prevarisc) ...");
                }
            } catch (Exception $e) {
                $output->writeln("Problème lors du versement de l'avis : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
