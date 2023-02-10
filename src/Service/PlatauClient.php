<?php

namespace App\Service;

use Exception;
use ReflectionClass;

/**
 * @property PlatauActeur       $acteurs
 * @property PlatauConsultation $consultations
 * @property PlatauHealthcheck  $healthcheck
 * @property PlatauNotification $notifications
 * @property PlatauPiece        $pieces
 */
class PlatauClient extends PlatauAbstract
{
    private static array $class_map = [
        'acteurs'       => PlatauActeur::class,
        'consultations' => PlatauConsultation::class,
        'healthcheck'   => PlatauHealthcheck::class,
        'notifications' => PlatauNotification::class,
        'pieces'        => PlatauPiece::class,
    ];

    /**
     * Initialisation et utilisation d'un service Platau.
     */
    public function __get(string $name)
    {
        $class_name = \array_key_exists($name, self::$class_map) ? self::$class_map[$name] : null;

        \assert(null !== $class_name, "Service $name inconnu");

        /* TODO Il faudrait idéalement que ça soit récursif, dans le cas où un service surchageant le
        constructeur de base a en paramètre un autre service surchargeant le constructeur de base */
        $class       = new ReflectionClass($class_name);
        $constructor = $class->getConstructor();
        $parameters  = $constructor->getParameters();

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
            return new $parameter_class($this->getConfig());
        }, $parameters_classes);

        return new $class_name($this->getConfig(), ...$parameters_classes);
    }
}
