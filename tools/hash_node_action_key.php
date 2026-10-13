<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

$key = (string) ($argv[1] ?? '');

if (strlen($key) < 24) {
    fwrite(STDERR, "Usage: php tools/hash_node_action_key.php '<node-action-key-min-24-chars>'\n");
    fwrite(STDERR, "Use a long random key and store only the printed hash in .env.\n");
    exit(1);
}

echo password_hash($key, PASSWORD_DEFAULT) . PHP_EOL;
