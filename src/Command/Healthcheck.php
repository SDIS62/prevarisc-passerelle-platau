<?php

namespace App\Command;

use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauHealthcheck as PlatauHealthcheckService;

final class Healthcheck extends Command
{
    /**
     * Initialisation de la commande.
     */
    public function __construct(PlatauHealthcheckService $healthcheck_service, PrevariscService $prevarisc_service)
    {
        $this->healthcheck_service = $healthcheck_service;
        $this->prevarisc_service   = $prevarisc_service;
        parent::__construct();
    }

    /**
     * Configuration de la commande.
     */
    protected function configure()
    {
        $this->setName('healthcheck')
            ->setDescription('Vérification de la configuration de la passerelle.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // On vérifie la santé de Plat'AU
        if (true !== $this->healthcheck_service->healthcheck()) {
            throw new \Exception("Plat'AU non fonctionnel actuellement.");
        }

        // On va maintenant tester la connexion à la base de données Prevarisc
        if (!$this->prevarisc_service->estDisponible()) {
            throw new \Exception('Base de données Prevarisc déconnectée.');
        }

        // On vérifie que la base de données Prevarisc est compatible avec Plat'AU
        if (!$this->prevarisc_service->estCompatible()) {
            throw new \Exception('Base de données Prevarisc incompatible. Avez-vous pensé à la mise à jour ?');
        }

        $output->writeln('RAS. Tout est disponible et prêt à l\'emploi !');

        return Command::SUCCESS;
    }
}
