<?php
declare(strict_types=1);

/**
 * Security headers. Geen inline scripts of inline styles: alles komt uit
 * /assets van dezelfde origin, zodat een geïnjecteerd stuk HTML niets kan uitvoeren.
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

if (APP_COOKIE_SECURE) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
