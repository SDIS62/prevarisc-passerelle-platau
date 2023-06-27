<?php

namespace App\ServiceProvider;

use App\Service;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;

final class Platau implements ServiceProvider
{
    /**
     * Construction du service provider avec un tableau de configuration.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $container) : void
    {
        $client = new Service\PlatauClient($this->config, $container);

        // Création des services Plat'AU
        $container->set('service.platau.consultation', fn () => $client->consultations);
        $container->set('service.platau.notification', fn () => $client->notifications);
        $container->set('service.platau.acteur', fn () => $client->acteurs);
        $container->set('service.platau.piece', fn () => $client->pieces);
        $container->set('service.platau.healthcheck', fn () => $client->healthcheck);
    }
}
