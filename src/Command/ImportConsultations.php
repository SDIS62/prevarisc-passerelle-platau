<?php

namespace App\Command;

use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use App\Service\PlatauActeur as PlatauActeurService;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;
use App\Service\PlatauNotification as PlatauNotificationService;

final class ImportConsultations extends Command
{
    private PrevariscService $prevarisc_service;
    private PlatauConsultationService $consultation_service;
    private PlatauActeurService $acteur_service;
    private PlatauNotificationService $notification_service;

    /**
     * Initialisation de la commande.
     */
    public function __construct(PrevariscService $prevarisc_service, PlatauConsultationService $consultation_service, PlatauNotificationService $notification_service, PlatauActeurService $acteur_service)
    {
        $this->prevarisc_service    = $prevarisc_service;
        $this->acteur_service       = $acteur_service;
        $this->consultation_service = $consultation_service;
        $this->notification_service = $notification_service;
        parent::__construct();
    }

    /**
     * Configuration de la commande.
     */
    protected function configure()
    {
        $this->setName('import')
            ->setDescription('Détecte et importe de nouvelles consultations dans Prevarisc.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Récupération des consultations dans un état Versée (c'est à dire Non Traitée)
        $output->writeln('Récupération des consultations versées  ...');
        $consultations = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => [1]]);

        // Si il n'existe pas de consultations, on arrête le travail ici
        if (empty($consultations)) {
            $output->writeln('Pas de consultations nous concernant. On vérifiera plus tard si il y en a de nouvelles !');

            return Command::SUCCESS;
        }

        // Si on se trouve ici, c'est qu'on a des consultations à traiter.
        foreach ($consultations as $consultation) {
            // On récupère l'identifant de la consultation
            $consultation_id = $consultation['idConsultation'];

            // Avec la consultation Platau, on va tenter de :
            // - Récupérer les données de la consultation
            // - Extraire les informations sur le projet, l'établissement concerné, le dossier ...
            // - Télécharger les pièces consultatives
            // - Injecter le tout dans Prevarisc
            try {
                // La consultation existe t'elle déjà dans Prevarisc ? Si oui, on ignore complètement la consultation
                if ($this->prevarisc_service->consultationExiste($consultation_id)) {
                    $output->writeln("Consultation $consultation_id déjà existante dans Prevarisc");
                    continue;
                }

                // On récupère les acteurs liés à la consultation
                $service_instructeur = null !== $consultation['dossier']['idServiceInstructeur'] ? $this->acteur_service->recuperationActeur($consultation['dossier']['idServiceInstructeur']) : null;
                $demandeur           = null !== $consultation['idServiceConsultant'] ? $this->acteur_service->recuperationActeur($consultation['idServiceConsultant']) : null;

                // Versement de la consultation dans Prevarisc et on passe l'état de sa PEC à 'awaiting'
                $this->prevarisc_service->importConsultation($consultation, $demandeur, $service_instructeur);
                $this->prevarisc_service->setMetadonneesEnvoi($consultation_id, 'PEC', 'awaiting')->executeStatement();

                // La consultation est importée !
                $output->writeln("Consultation $consultation_id récupérée et stockée dans Prevarisc !");
            } catch (\Exception $e) {
                $output->writeln("Problème lors du traitement de la consultation : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
