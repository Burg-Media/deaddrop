<?php
declare(strict_types=1);

/**
 * Welke bestanden horen bij een release. Alles wat code is of naar de browser
 * gaat; niet .env, niet de opslag, niet interne notities.
 */
function manifest_file_list(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = substr($file->getPathname(), strlen(APP_ROOT) + 1);
        $path = str_replace('\\', '/', $path);

        if (!preg_match('#^(public|includes|actions|config|cron|database|tools)/.+\.(php|js|css|sql)$#', $path)) {
            continue;
        }

        $files[] = $path;
    }

    sort($files, SORT_STRING);

    return $files;
}
