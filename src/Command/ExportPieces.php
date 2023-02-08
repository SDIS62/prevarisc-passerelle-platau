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
use Symfony\Component\Console\Style\SymfonyStyle;

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
        $command_style = new SymfonyStyle($input, $output);
        $command_style->title('Export des pièces');

        if (null === $this->syncplicity_client) {
            $command_style->error("Impossible d'utiliser {$this->getName()} sans l'activation du service syncplicity");
            
            return Command::FAILURE;
        }

        $files_to_export = $this->prevarisc_service->recupererPiecesAvecStatut('to_be_exported');

        if (0 === count($files_to_export)) {
            $command_style->info("Aucune pièce à téléverser");

            return Command::SUCCESS;
        }

        array_map(function ($db_value) use ($command_style) {
            $file_contents = $this->prevarisc_service->recupererFichierPhysique($db_value['ID_PIECEJOINTE'], $db_value['EXTENSION_PIECEJOINTE']);

            $command_style->text(sprintf("Téléversement de la pièce \"%s%s\"", $db_value['NOM_PIECEJOINTE'], $db_value['EXTENSION_PIECEJOINTE']));
            $file = $this->syncplicity_client->upload($file_contents);

            \assert(\array_key_exists('data_file_id', $file));
            $syncplicity_file_id = $file['data_file_id'];

            \assert(\array_key_exists('VirtualFolderId', $file));
            $syncplicity_folder_id = $file['VirtualFolderId'];

            $dossier_id = $db_value['ID_PLATAU'];

            $this->piece_service->ajouterPieceDepuisFichierSyncplicity(
                '',
                $dossier_id,
                '',
                '',
                '',
                $syncplicity_file_id,
                $syncplicity_folder_id,
                hash('sha512', $file_contents)
            );
        }, $files_to_export);

        $command_style->success("Les pièces ont correctement été téléversées");

        return Command::SUCCESS;
    }
}
