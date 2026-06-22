<?php

/**
 * Returns a shared PDO connection to the MySQL database.
 * Credentials are loaded from environment variables — never hardcoded.
 * Throws PDOException on connection failure (ERRMODE_EXCEPTION enforced).
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $host    = getenv('DB_HOST') ?: 'localhost';
        $name    = getenv('DB_NAME') ?: 'requirement_orchestrator';
        $user    = getenv('DB_USER') ?: 'root';
        $pass    = getenv('DB_PASS') ?: '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$name;charset=$charset";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
