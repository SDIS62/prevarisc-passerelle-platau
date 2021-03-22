<?php

namespace App\ServiceProvider;

use League\Flysystem;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;

final class FileStorageSystem implements ServiceProvider
{
    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $container) : void
    {
        // Configuration d'environnement pour créer une instance FlySystem
        $config = [
            'base_path' => '/',
        ];

        // Création d'une instance FlySystem
        $adapter    = new Flysystem\Local\LocalFilesystemAdapter($config['base_path']);
        $filesystem = new Flysystem\Filesystem($adapter);

        // On stocke le service de gestion de fichiers dans le container
        $container->set('filesystem', $filesystem);
    }
}
