<?php

namespace App\ServiceProvider;

use League\Flysystem;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;
use Doctrine\DBAL\DriverManager;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Prevarisc implements ServiceProvider
{
    private array $config;

    /**
     * Construction du service provider avec un tableau de configuration.
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired([
            'PREVARISC_DB_NAME',
            'PREVARISC_DB_USER',
            'PREVARISC_DB_PASSWORD',
            'PREVARISC_DB_HOST',
            'PREVARISC_DB_DRIVER',
            'PREVARISC_DB_CHARSET',
            'PREVARISC_DB_PORT',
            'PREVARISC_DB_PLATAU_USER_ID',
            'PREVARISC_PIECES_JOINTES_PATH',
        ]);
        $this->config = $resolver->resolve($config);
    }

    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $c) : void
    {
        // Récupération d'une connexion via le driver de la base de données cible
        $connection = DriverManager::getConnection([
            'dbname'   => (string) $this->config['PREVARISC_DB_NAME'],
            'user'     => (string) $this->config['PREVARISC_DB_USER'],
            'password' => (string) $this->config['PREVARISC_DB_PASSWORD'],
            'host'     => (string) $this->config['PREVARISC_DB_HOST'],
            'driver'   => (string) $this->config['PREVARISC_DB_DRIVER'],
            'charset'  => (string) $this->config['PREVARISC_DB_CHARSET'],
            'port'     => (int) $this->config['PREVARISC_DB_PORT'],
        ]);

        // Création d'une instance FlySystem pour stocker les pièces jointes
        $adapter    = new Flysystem\Local\LocalFilesystemAdapter($this->config['PREVARISC_PIECES_JOINTES_PATH']);
        $filesystem = new Flysystem\Filesystem($adapter);

        // Initialisation du service Prevarisc
        $service = new PrevariscService($connection, $this->config['PREVARISC_DB_PLATAU_USER_ID'], $filesystem);

        // On stocke le service dans le container
        $c->set('service.prevarisc', $service);
    }
}
