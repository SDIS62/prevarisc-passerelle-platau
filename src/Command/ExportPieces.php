<?php

namespace App\Command;

use Exception;
use App\Service\SyncplicityClient;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        $command_style = new SymfonyStyle($input, $output);
        $command_style->title('Export des pièces');

        if (null === $this->syncplicity_client) {
            $command_style->error("Impossible d'utiliser {$this->getName()} sans l'activation du service syncplicity");

            return Command::FAILURE;
        }

        $files_to_export = $this->prevarisc_service->recupererPiecesAvecStatut('to_be_exported');

        if (0 === \count($files_to_export)) {
            $command_style->info('Aucune pièce à téléverser');

            return Command::SUCCESS;
        }

        foreach ($files_to_export as $file_to_export) {
            $file_contents = $this->prevarisc_service->recupererFichierPhysique($file_to_export['ID_PIECEJOINTE'], $file_to_export['EXTENSION_PIECEJOINTE']);
            $file_name     = $file_to_export['NOM_PIECEJOINTE'].$file_to_export['EXTENSION_PIECEJOINTE'];

            $command_style->text(sprintf('Téléversement de la pièce "%s" vers Syncplicity', $file_name));

            try {
                $file = $this->syncplicity_client->upload($file_contents, $file_name);

                $command_style->info('La pièce a correctement été téléversée vers Syncplicity');
            } catch (Exception $e) {
                $command_style->warning('Erreur lors du téléversement de la pièce vers Syncplicity.'.\PHP_EOL.$e->getMessage());
                $this->prevarisc_service->changerStatutPiece($file_to_export['ID_PIECEJOINTE'], 'on_error');

                continue;
            }

            \assert(\array_key_exists('data_file_id', $file));
            $syncplicity_file_id = $file['data_file_id'];

            \assert(\array_key_exists('VirtualFolderId', $file));
            $syncplicity_folder_id = $file['VirtualFolderId'];

            // FIXME Ajouter l'ID_DOSSIER en base Prevarisc, et ne faire une requête que si nécessaire (non dispo en base)
            $consultation_id = $file_to_export['ID_PLATAU'];
            $consultations   = $this->consultation_service->rechercheConsultations(['idConsultation' => $consultation_id]);

            if (1 !== \count($consultations)) {
                $command_style->warning("La consultation \"%s\" n'a pas pu être récupérée sur Plat'AU", $consultation_id);
            }

            $consultation = current($consultations);
            $dossier_id   = $consultation['dossier']['idDossier'];
            $command_style->text(sprintf("Ajout de la pièce au dossier Plat'AU \"%s\"", $dossier_id));

            // TODO A refaire, on peut pas utiliser l'endpoint /pieces en tant que Service Consultable
            $this->piece_service->ajouterPieceDepuisFichierSyncplicity(
                '',
                $dossier_id,
                1,
                2, // Modificative
                60, // Etude de sécurité
                $syncplicity_file_id,
                $syncplicity_folder_id,
                hash('sha512', $file_contents)
            );

            $this->prevarisc_service->changerStatutPiece($file_to_export['ID_PIECEJOINTE'], 'exported');
        }

        $command_style->success("Fin d'export des pièces");

        return Command::SUCCESS;
    }
}
