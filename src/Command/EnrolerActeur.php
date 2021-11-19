<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use App\Service\PlatauActeur as PlatauActeurService;
use Symfony\Component\Console\Output\OutputInterface;

final class EnrolerActeur extends Command
{
    /**
     * Initialisation de la commande.
     */
    public function __construct(PlatauActeurService $acteur_service)
    {
        $this->acteur_service = $acteur_service;
        parent::__construct();
    }

    /**
     * Configuration de la commande.
     */
    protected function configure()
    {
        $this->setName('enroler-acteur')
            ->setDescription("Enrôlement d'un nouvel acteur dans Plat'AU")
            ->addOption('designation', null, InputOption::VALUE_REQUIRED, 'Désignation du service consultable')
            ->addOption('mail', null, InputOption::VALUE_REQUIRED, 'Mail de contact')
            ->addOption('siren', null, InputOption::VALUE_REQUIRED, 'Numéro SIREN')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Récupération des données de l'acteur à créer
        $designation = $input->getOption('designation');
        $mail        = $input->getOption('mail');
        $siren       = $input->getOption('siren');

        $output->writeln("Enrôlement d'un nouvel acteur sur Plat'AU ... ");

        // Enrôlement Plat'AU !
        $id_acteur = $this->acteur_service->enrolerServiceConsultable($designation, $mail, $siren);

        $output->writeln("Acteur enrolé ! Son identifiant Plat'AU est : $id_acteur");

        return Command::SUCCESS;
    }
}
