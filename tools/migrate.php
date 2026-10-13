<?php
declare(strict_types=1);

/**
 * Voert de migraties in database/migrations/ uit, op volgorde, elk maar één keer.
 *
 *   php tools/migrate.php                 nieuwe migraties uitvoeren
 *   php tools/migrate.php --status        tonen wat wel en niet gedraaid is
 *   php tools/migrate.php --baseline=005  bestaande node: markeer 000 t/m 005 als gedaan
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/app.php';

$options = getopt('', ['status', 'baseline:']);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$files = glob(APP_ROOT . '/database/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
sort($files, SORT_STRING);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

if (isset($options['baseline'])) {
    $upTo = (string) $options['baseline'];
    $mark = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)');

    foreach ($files as $file) {
        $name = basename($file);

        if (strcmp(substr($name, 0, strlen($upTo)), $upTo) <= 0) {
            $mark->execute([$name]);
            echo "baseline  {$name}\n";
        }
    }

    exit(0);
}

if (isset($options['status'])) {
    foreach ($files as $file) {
        $name = basename($file);
        echo (isset($applied[$name]) ? 'gedaan    ' : 'open      ') . $name . "\n";
    }

    exit(0);
}

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        continue;
    }

    echo "migreren  {$name} ... ";

    try {
        if (str_ends_with($name, '.php')) {
            $migration = require $file;
            $migration($pdo);
        } else {
            foreach (split_sql((string) file_get_contents($file)) as $statement) {
                $pdo->exec($statement);
            }
        }
    } catch (Throwable $e) {
        echo "mislukt\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $mark = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
    $mark->execute([$name]);
    echo "ok\n";
    $ran++;
}

echo $ran === 0 ? "Database is up-to-date.\n" : "{$ran} migratie(s) uitgevoerd.\n";

/**
 * Splitst een SQL-bestand op puntkomma's buiten strings, zonder commentaarregels.
 */
function split_sql(string $sql): array
{
    $lines = array_filter(
        explode("\n", $sql),
        static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
    );
    $sql = implode("\n", $lines);

    $statements = [];
    $buffer = '';
    $quote = null;

    for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            if ($char === $quote && $sql[$i - 1] !== '\\') {
                $quote = null;
            }
        } elseif ($char === "'" || $char === '"') {
            $quote = $char;
        } elseif ($char === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }

            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}
