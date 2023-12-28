<?php

namespace App\ServiceProvider;

use SDIS62\Syncplicity\SyncplicityClient;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;

final class Syncplicity implements ServiceProvider
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
        $c->set(SyncplicityClient::class, fn () => new SyncplicityClient($this->config));
    }
}
