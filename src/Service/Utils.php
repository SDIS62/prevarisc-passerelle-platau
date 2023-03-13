<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class Utils
{
    public const NECESSARY_TABLES = [
        'piecejointestatut',
        'platauconsultation',
    ];

    public static function containsNecessaryTables(Connection $db) : bool
    {
        return \count(array_intersect($db->createSchemaManager()->listTableNames(), self::NECESSARY_TABLES)) === \count(self::NECESSARY_TABLES);
    }
}
