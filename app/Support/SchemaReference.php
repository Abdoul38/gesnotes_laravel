<?php

namespace App\Support;

/**
 * Schéma de référence d'une base d'université
 * (database/schema/universite-schema.sql, extrait de la base UDH).
 */
final class SchemaReference
{
    /**
     * Tables du schéma dont la version Laravel ne se sert pas : leur absence
     * n'empêche pas la bascule (l'ancienne application les créait à la
     * demande pour ses propres besoins).
     */
    public const INUTILISEES = [
        'login_tentatives', // limite de connexions de l'ancienne application (Laravel : RateLimiter)
        'mesr_cache',       // cache de l'ancien tableau de bord du ministère (Laravel : cache de l'application)
    ];

    /** @return array<string, string> nom de table => instruction CREATE TABLE */
    public static function creations(): array
    {
        $sql = (string) file_get_contents(database_path('schema/universite-schema.sql'));
        preg_match_all('/^CREATE TABLE `([^`]+)` \(.*?\) ENGINE=[^;]*;/ms', $sql, $m, PREG_SET_ORDER);
        $creations = [];
        foreach ($m as [$instruction, $nom]) {
            $creations[$nom] = $instruction;
        }

        return $creations;
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_keys(self::creations());
    }
}
