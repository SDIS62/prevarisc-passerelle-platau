<?php

namespace App\Command;

use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use App\Service\PlatauPiece as PlatauPieceService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\PlatauConsultation as PlatauConsultationService;

final class ImportPieces extends Command
{
    private PrevariscService $prevarisc_service;
    private PlatauConsultationService $consultation_service;
    private PlatauPieceService $piece_service;

    /**
     * Initialisation de la commande.
     */
    public function __construct(PrevariscService $prevarisc_service, PlatauConsultationService $consultation_service, PlatauPieceService $piece_service)
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
        $this->setName('import-pieces')
            ->setDescription('Détecte et importe / met à jour des pièces relatives aux consultations importées dans Prevarisc.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration')
            ->addOption('force-non-pec', null, InputOption::VALUE_NONE, 'Force le téléchargement des pièces des consultations non Prises En Charge');
    }

    /**
     * Logique d'execution de la commande.
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Récupération des consultations un état "Prise en compte - en cours de traitement"
        // Si le flag --force-non-pec est ajouté par l'utilisateur, on récupère TOUTES les consultations versées
        if ($input->getOption('force-non-pec')) {
            $output->writeln('Recherche de toutes les consultations versées (--force-non-pec) ...');
            $consultations = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => [1]]);
        } else {
            $output->writeln('Recherche de toutes les consultations en attente d\'avis ...');
            $consultations = $this->consultation_service->rechercheConsultations(['nomEtatConsultation' => [3]]);
        }

        // Si il n'existe pas de consultations, on arrête le travail ici
        if (empty($consultations)) {
            $output->writeln('Aucune consultation trouvée pour lesquelles nous devons chercher des pièces jointes.');

            return Command::SUCCESS;
        }

        // Si on se trouve ici, c'est qu'on a des consultations à traiter.
        foreach ($consultations as $consultation) {
            // On récupère l'identifiant de la consultation
            $consultation_id = $consultation['idConsultation'];

            // Avec la consultation Platau, on va tenter de récupérer l'ensemble des pièces du dossier concerné
            try {
                // Vérification de l'existence de la consultation existe dans Prevarisc ? Si non, on ignore complètement la consultation
                if (!$this->prevarisc_service->consultationExiste($consultation_id)) {
                    $output->writeln("La consultation $consultation_id n'existe pas dans Prevarisc. Importez là d'abord avec la command <import>.");
                    continue;
                }

                // Récupération du dossier Prevarisc lié à cette consultation
                $dossier_prevarisc = $this->prevarisc_service->recupererDossierDeConsultation($consultation_id);

                // Récupération des pièces jointes liées à la consultation
                foreach ($this->consultation_service->getPieces($consultation_id) as $piece) {
                    // Téléchargement de la pièce
                    $http_response = $this->piece_service->download($piece);

                    // On essaie de trouver l'extension de la pièce jointe
                    $extension = $this->piece_service->getExtensionFromHttpResponse($http_response) ?? '???';

                    // Récupération du contenu de la pièce jointe
                    $file_contents = $http_response->getBody()->getContents();

                    // Insertion dans Prevarisc
                    $this->prevarisc_service->creerPieceJointe($dossier_prevarisc['ID_DOSSIER'], $piece, $extension, $file_contents);
                }

                // La consultation est importée !
                $output->writeln("Consultation $consultation_id récupérée et stockée dans Prevarisc !");
            } catch (\Exception $e) {
                $output->writeln("Problème lors du traitement de la consultation : {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
