<?php
declare(strict_types=1);

/**
 * Hulpje voor tests/e2e.mjs: één SQL-query tegen de testdatabase, resultaat als JSON.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/app.php';

if (!str_contains((string) env('DB_DATABASE', ''), 'test')) {
    fwrite(STDERR, "Weigert: alleen tegen een testdatabase.\n");
    exit(1);
}

$stmt = $pdo->query($argv[1] ?? 'SELECT 1');
echo json_encode($stmt->columnCount() > 0 ? $stmt->fetchAll() : ['affected' => $stmt->rowCount()]);
