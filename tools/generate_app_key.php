<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

echo bin2hex(random_bytes(32)) . PHP_EOL;
