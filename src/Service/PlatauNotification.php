<?php

namespace App\Service;

final class PlatauNotification extends PlatauAbstract
{
    /**
     * Recherche de plusieurs notifications.
     */
    public function rechercheNotifications(array $params = []) : array
    {
        // On recherche la consultation en fonction des critères de recherche
        $response = $this->request('get', 'notifications', ['query' => $params]);

        // On vient récupérer les notifications qui nous interesse dans la réponse des résultats de recherche
        $notifications = json_decode($response->getBody()->__toString(), true, 512, \JSON_THROW_ON_ERROR);

        \assert(\is_array($notifications));

        // Les notifications se trouvent normalement sous la clé "notifications" du tableau renvoyé par l'API Plat'AU
        if (!\array_key_exists('notifications', $notifications)) {
            throw new \Exception('Un problème a eu lieu dans la récupération des résultats de recherche de notifications : clé notifications introuvable');
        }

        // Le résultat de la recherche doit donner un tableau, sinon, il y a un problème quelque part ...
        if (!\is_array($notifications['notifications'])) {
            throw new \Exception('Un problème a eu lieu dans la récupération des résultats de recherche de notifications : le résultat est incorrect');
        }

        $set = $notifications['notifications'];

        return $set;
    }
}
