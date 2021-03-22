<?php

namespace App\ServiceProvider;

use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;
use Doctrine\DBAL\DriverManager;
use App\Service\Prevarisc as PrevariscService;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Prevarisc implements ServiceProvider
{
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
        ]);
        $this->config = $resolver->resolve($config);
    }

    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $container) : void
    {
        // Récupération d'une connexion via le driver de la base de données cible
        $connection = DriverManager::getConnection([
            'dbname'   => $this->config['PREVARISC_DB_NAME'],
            'user'     => $this->config['PREVARISC_DB_USER'],
            'password' => $this->config['PREVARISC_DB_PASSWORD'],
            'host'     => $this->config['PREVARISC_DB_HOST'],
            'driver'   => $this->config['PREVARISC_DB_DRIVER'],
            'charset'  => $this->config['PREVARISC_DB_CHARSET'],
            'port'     => $this->config['PREVARISC_DB_PORT'],
        ]);

        // Initialisation du service Prevarisc
        $service = new PrevariscService($connection, $this->config['PREVARISC_DB_PLATAU_USER_ID']);

        // On stocke le service dans le container
        $container->set('service.prevarisc', $service);
    }
}
