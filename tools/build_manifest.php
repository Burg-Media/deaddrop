<?php
declare(strict_types=1);

/**
 * Maakt release-manifest.json: per bestand een SHA-256, en voor alles in
 * public/assets ook de SRI-hash die de pagina's in hun <script>-tags zetten.
 *
 *   php tools/build_manifest.php
 *
 * Het manifest wordt meegecommit in de release-tag op GitHub. Iedereen kan het
 * vergelijken met wat een node uitlevert: zie verify.php en docs/verify.md.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/manifest.php';

$version = trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'dev';
$files = [];

foreach (manifest_file_list() as $path) {
    $full = APP_ROOT . '/' . $path;
    $entry = ['sha256' => hash_file('sha256', $full)];

    if (str_starts_with($path, 'public/assets/')) {
        $entry['sri'] = 'sha384-' . base64_encode(hash_file('sha384', $full, true));
    }

    $files[$path] = $entry;
}

$manifest = [
    'name' => 'deaddrop',
    'version' => $version,
    'files' => $files,
];

file_put_contents(
    APP_ROOT . '/release-manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo 'release-manifest.json: ' . count($files) . " bestanden, versie {$version}\n";
echo 'sha256: ' . hash_file('sha256', APP_ROOT . '/release-manifest.json') . "\n";
