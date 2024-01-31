<?php

namespace App\Service;

/**
 * @property PlatauActeur       $acteurs
 * @property PlatauConsultation $consultations
 * @property PlatauHealthcheck  $healthcheck
 * @property PlatauNotification $notifications
 * @property PlatauPiece        $pieces
 * @property PlatauAvis         $avis
 */
class PlatauClient extends PlatauAbstract
{
    private static array $class_map = [
        'acteurs'       => PlatauActeur::class,
        'consultations' => PlatauConsultation::class,
        'healthcheck'   => PlatauHealthcheck::class,
        'notifications' => PlatauNotification::class,
        'pieces'        => PlatauPiece::class,
        'avis'          => PlatauAvis::class,
    ];

    /**
     * Initialisation et utilisation d'un service Platau.
     */
    public function __get(string $name)
    {
        /** @var class-string<PlatauAbstract>|null $class_name */
        $class_name = \array_key_exists($name, self::$class_map) ? self::$class_map[$name] : null;

        \assert(null !== $class_name, "Service $name inconnu");

        $service = new $class_name($this->getConfig());

        $synclicity = $this->getSyncplicity();
        if (null !== $synclicity) {
            $service->enableSyncplicity($synclicity);
        }

        return $service;
    }
}
