<?php

namespace App\ServiceProvider;

use App\Service;
use UMA\DIC\Container;
use UMA\DIC\ServiceProvider;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Platau implements ServiceProvider
{
    public const PLATAU_URL             = 'https://api.aife.economie.gouv.fr/mtes_preprod/platau/v5/';
    public const PISTE_ACCESS_TOKEN_URL = 'https://oauth.aife.economie.gouv.fr/api/oauth/token';

    /**
     * Construction du service provider avec un tableau de configuration.
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['PLATAU_URL' => self::PLATAU_URL, 'PISTE_ACCESS_TOKEN_URL' => self::PISTE_ACCESS_TOKEN_URL]);
        $resolver->setRequired(['PISTE_CLIENT_ID', 'PISTE_CLIENT_SECRET', 'PLATAU_ID_ACTEUR_APPELANT']);
        $this->config = $resolver->resolve($config);
    }

    /**
     * Setup PSR11 container's configuration from environment variables.
     */
    public function provide(Container $container) : void
    {
        $config = [
            'PLATAU_URL' => $this->config['PLATAU_URL'],
            'PISTE_ACCESS_TOKEN_URL' => $this->config['PISTE_ACCESS_TOKEN_URL'],
            // Configuration d'environnement d'auth sur PISTE (https://developer.aife.economie.gouv.fr)
            'PISTE_CLIENT_ID'        => $this->config['PISTE_CLIENT_ID'],
            'PISTE_CLIENT_SECRET'    => $this->config['PISTE_CLIENT_SECRET'],
            // Configuration du client Plat'AU
            'PLATAU_ID_ACTEUR_APPELANT' => $this->config['PLATAU_ID_ACTEUR_APPELANT'],
        ];

        // Création des services Plat'AU
        $container->set('service.platau.consultation', new Service\PlatauConsultation($config));
        $container->set('service.platau.notification', new Service\PlatauNotification($config));
        $container->set('service.platau.acteur', new Service\PlatauActeur($config));
        $container->set('service.platau.piece', new Service\PlatauPiece($config));
        $container->set('service.platau.healthcheck', new Service\PlatauHealthcheck($config));
    }
}
