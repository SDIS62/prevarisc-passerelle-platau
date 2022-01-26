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
        // Création des services Plat'AU
        $container->set('service.platau.consultation', new Service\PlatauConsultation($this->config));
        $container->set('service.platau.notification', new Service\PlatauNotification($this->config));
        $container->set('service.platau.acteur', new Service\PlatauActeur($this->config));
        $container->set('service.platau.piece', new Service\PlatauPiece($this->config));
        $container->set('service.platau.healthcheck', new Service\PlatauHealthcheck($this->config));
    }
}
