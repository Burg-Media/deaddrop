<?php
declare(strict_types=1);

/**
 * Asset-tags met Subresource Integrity. Bij een release staan de hashes in
 * release-manifest.json (tools/build_manifest.php); zonder manifest rekent de
 * node ze zelf uit. Zo legt elke pagina vast welke exacte JavaScript ze laadt,
 * en is die te vergelijken met het gepubliceerde manifest (zie verify.php).
 */

function release_manifest(): array
{
    static $manifest = null;

    if ($manifest === null) {
        $file = APP_ROOT . '/release-manifest.json';
        $manifest = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    return $manifest;
}

function asset_integrity(string $path): string
{
    $fromManifest = release_manifest()['files']['public/' . $path]['sri'] ?? null;

    if (is_string($fromManifest) && $fromManifest !== '') {
        return $fromManifest;
    }

    $file = APP_ROOT . '/public/' . $path;

    return is_file($file) ? 'sha384-' . base64_encode(hash_file('sha384', $file, true)) : '';
}

function script_tag(string $path): string
{
    return '<script src="' . e(APP_URL . '/' . $path) . '" integrity="' . e(asset_integrity($path)) . '"></script>';
}

function stylesheet_tag(string $path): string
{
    return '<link rel="stylesheet" href="' . e(APP_URL . '/' . $path) . '" integrity="' . e(asset_integrity($path)) . '">';
}
