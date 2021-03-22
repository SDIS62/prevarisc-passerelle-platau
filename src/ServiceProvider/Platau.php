<?php

namespace App\ServiceProvider;

use App\Service;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Platau implements ServiceProvider
{
    /**
     * Construction du service provider avec un tableau de configuration.
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['PISTE_CLIENT_ID', 'PISTE_CLIENT_SECRET', 'PLATAU_ID_ACTEUR_APPELANT']);
        $this->config = $resolver->resolve($config);
    }

    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $container) : void
    {
        $config = [
            // Configuration d'environnement d'auth sur PISTE (https://developer.aife.economie.gouv.fr)
            'PISTE_CLIENT_ID'        => getenv('PISTE_CLIENT_ID'),
            'PISTE_CLIENT_SECRET'    => getenv('PISTE_CLIENT_SECRET'),
            // Configuration du client Plat'AU
            'PLATAU_ID_ACTEUR_APPELANT' => getenv('PLATAU_ID_ACTEUR_APPELANT'),
        ];

        // Création des services Plat'AU
        $container->set('service.platau.consultation', new Service\PlatauConsultation($config));
        $container->set('service.platau.notification', new Service\PlatauNotification($config));
        $container->set('service.platau.acteur', new Service\PlatauActeur($config));
        $container->set('service.platau.healthcheck', new Service\PlatauHealthcheck($config));
    }
}
