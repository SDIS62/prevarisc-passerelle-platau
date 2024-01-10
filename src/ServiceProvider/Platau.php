<?php

namespace App\ServiceProvider;

use App\Service;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;
use App\Service\SyncplicityClient;

final class Platau implements ServiceProvider
{
    private array $config;

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
    public function provide(Container $c) : void
    {
        $client = new Service\PlatauClient($this->config);

        if ($c->has(Service\SyncplicityClient::class)) {
            $syncplicity = $c->get(Service\SyncplicityClient::class);
            \assert($syncplicity instanceof SyncplicityClient);
            $client->enableSyncplicity($syncplicity);
        }

        // Création des services Plat'AU
        $c->set('service.platau.consultation', fn () => $client->consultations);
        $c->set('service.platau.notification', fn () => $client->notifications);
        $c->set('service.platau.acteur', fn () => $client->acteurs);
        $c->set('service.platau.piece', fn () => $client->pieces);
        $c->set('service.platau.healthcheck', fn () => $client->healthcheck);
    }
}
