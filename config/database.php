<?php
declare(strict_types=1);

/**
 * Databaseconnectie via PDO.
 */

$host = (string) env('DB_HOST', 'localhost');
$port = (string) env('DB_PORT', '3306');
$db   = (string) env('DB_DATABASE', '');
$user = (string) env('DB_USERNAME', '');
$pass = (string) env('DB_PASSWORD', '');
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('Deaddrop database connection failed: ' . $e->getMessage());
    show_setup_error(['De database is niet bereikbaar. Controleer DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME en DB_PASSWORD.']);
}
