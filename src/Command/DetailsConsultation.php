<?php

namespace App\Command;

use Adbar\Dot;
use App\Service\PlatauConsultation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DetailsConsultation extends Command
{
    private PlatauConsultation $consultation_service;

    public function __construct(PlatauConsultation $consultation_service)
    {
        $this->consultation_service = $consultation_service;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('details-consultation')
            ->setDescription("Affiche les détails d'une consultation.")
            ->addArgument('consultation-id', InputArgument::REQUIRED, 'Consultation concernée')
            ->addOption('champ', null, InputOption::VALUE_OPTIONAL, 'Retourne un champ spécifique')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier de configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        // Récupération de la consultation demandée ...
        $output->writeln('Récupération de la consultation concernée ...');
        $consultation_id = $input->getArgument('consultation-id');
        $consultation    = $this->consultation_service->getConsultation($consultation_id);

        // On active la "dot notation" sur les données de la consultation afin de rendre plus facile la lecture
        $rowset = new Dot($consultation);

        // Si l'utilisateur demande un champ particulier on lui donne
        // Sinon, on retourne l'ensemble des champs de la consultation
        if ($input->getOption('champ')) {
            $output->writeln($input->getOption('champ').' : '.$rowset->get($input->getOption('champ'), 'Aucune donnée'));
        } else {
            foreach ($rowset->flatten() as $key => $value) {
                $output->writeln($key.' : '.$value);
            }
        }

        return Command::SUCCESS;
    }
}
