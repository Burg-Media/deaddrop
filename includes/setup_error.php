<?php
declare(strict_types=1);

/**
 * Nette stop bij een onvolledige installatie, zonder stacktrace of secrets.
 */
function show_setup_error(array $errors): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Deaddrop is niet klaar om te starten:\n");

        foreach ($errors as $error) {
            fwrite(STDERR, '  - ' . $error . "\n");
        }

        exit(1);
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $items = '';

    foreach ($errors as $error) {
        $items .= '<li>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>Installatie niet compleet</title></head>'
        . '<body><main><h1>Deze node is nog niet klaar</h1>'
        . '<p>De beheerder moet eerst de configuratie in <code>.env</code> afmaken:</p>'
        . '<ul>' . $items . '</ul>'
        . '<p>Zie <code>docs/install.md</code> in de repository.</p></main></body></html>';
    exit;
}
