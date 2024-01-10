<?php

namespace App\Service;

use Exception;
use ReflectionClass;
use UMA\DIC\Container;

/**
 * @property PlatauActeur       $acteurs
 * @property PlatauConsultation $consultations
 * @property PlatauHealthcheck  $healthcheck
 * @property PlatauNotification $notifications
 * @property PlatauPiece        $pieces
 */
class PlatauClient extends PlatauAbstract
{
    private Container $container;

    private static array $class_map = [
        'acteurs'       => PlatauActeur::class,
        'consultations' => PlatauConsultation::class,
        'healthcheck'   => PlatauHealthcheck::class,
        'notifications' => PlatauNotification::class,
        'pieces'        => PlatauPiece::class,
    ];

    public function __construct(array $config, Container $container)
    {
        $this->container = $container;
        parent::__construct($config);
    }

    /**
     * Initialisation et utilisation d'un service Platau.
     */
    public function __get(string $name)
    {
        /** @var class-string<PlatauAbstract>|null $class_name */
        $class_name = \array_key_exists($name, self::$class_map) ? self::$class_map[$name] : null;

        \assert(null !== $class_name, "Service $name inconnu");

        return $this->instantiateClass($class_name);
    }

    /**
     * Permet d'instantier une classe avec les bons paramètres.
     */
    private function instantiateClass(string $class_name)
    {
        $class              = new ReflectionClass($class_name);
        $constructor        = $class->getConstructor();
        $parameters         = $constructor->getParameters();
        $parameters_classes = [];

        foreach ($parameters as $parameter) {
            $parameter_name = $parameter->getName();

            if ('config' === $parameter_name) {
                continue;
            }

            $parameter_type = $parameter->getType();

            if (null === $parameter_type) {
                throw new Exception("Le paramètre \"{$parameter_name}\" n'a pas de TypeHint");
            }

            $parameters_classes[] = $parameter_type->getName();
        }

        $parameters_classes = array_map(function ($parameter_class) {
            if ('App\Service\Prevarisc' === $parameter_class) {
                return $this->container->get('service.prevarisc');
            }

            if (SyncplicityClient::class === $parameter_class) {
                return $this->container->get(SyncplicityClient::class);
            }

            return $this->instantiateClass($parameter_class);
        }, $parameters_classes);

        return new $class_name($this->getConfig(), ...$parameters_classes);
    }
}
