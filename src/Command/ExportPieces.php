<?php

namespace App\Command;

use App\Service\SyncplicityClient;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use App\Service\PlatauPiece as PlatauPieceService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ExportPieces extends Command
{
    /**
     * Initialisation de la commande.
     */
    public function __construct(
        private PrevariscService $prevarisc_service,
        private PlatauConsultationService $consultation_service,
        private PlatauPieceService $piece_service,
        private ?SyncplicityClient $syncplicity_client = null
    ) {
        parent::__construct();
    }

    /**
     * Configuration de la commande.
     */
    protected function configure()
    {
        $this->setName('export-pieces')
            ->setDescription('Téléverse des pièces relatives aux dossiers Prevarisc.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        if (null === $this->syncplicity_client) {
            $output->writeln("Impossible d'utiliser export-pieces sans l'activation du service syncplicity");

            return Command::FAILURE;
        }

        $file_contents = '';

        $file = $this->syncplicity_client->upload($file_contents);

        \assert(\array_key_exists('data_file_id', $file));
        $syncplicity_file_id = $file['data_file_id'];

        \assert(\array_key_exists('VirtualFolderId', $file));
        $syncplicity_folder_id = $file['VirtualFolderId'];

        $this->piece_service->ajouterPieceDepuisFichierSyncplicity(
            '',
            '',
            '',
            '',
            '',
            $syncplicity_file_id,
            $syncplicity_folder_id,
            hash('sha512', $file_contents)
        );

        return Command::SUCCESS;
    }
}
