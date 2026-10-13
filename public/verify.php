<?php
declare(strict_types=1);

/**
 * Publieke verificatiepagina: welke versie draait hier, en welke bestanden
 * levert deze node écht uit. Vergelijk de hashes met het ondertekende
 * release-manifest uit de repository (docs/verify.md).
 *
 * Dit maakt manipulatie niet onmogelijk (de node kan ook deze pagina
 * aanpassen), wel zichtbaar voor wie zelf vergelijkt: de JavaScript die je
 * browser krijgt, heeft een hash die je in devtools kunt nameten.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/manifest.php';

$manifestFile = APP_ROOT . '/release-manifest.json';
$manifest = release_manifest();
$manifestHash = is_file($manifestFile) ? hash_file('sha256', $manifestFile) : null;
$expected = $manifest['files'] ?? [];

$rows = [];
$mismatches = 0;

foreach (manifest_file_list() as $path) {
    $live = hash_file('sha256', APP_ROOT . '/' . $path);
    $wanted = $expected[$path]['sha256'] ?? null;
    $state = $wanted === null ? 'niet in manifest' : (hash_equals($wanted, $live) ? 'gelijk' : 'afwijkend');

    if ($state !== 'gelijk') {
        $mismatches++;
    }

    $rows[] = ['path' => $path, 'sha256' => $live, 'state' => $state];
}

foreach (array_diff(array_keys($expected), array_column($rows, 'path')) as $missing) {
    $rows[] = ['path' => $missing, 'sha256' => '', 'state' => 'ontbreekt'];
    $mismatches++;
}

$pageTitle = 'Verificatie';

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <h1>Controleer deze node</h1>

    <p>
        Deaddrop versleutelt in je browser, met JavaScript die deze node uitlevert. Wie de node beheert,
        kan die JavaScript in theorie aanpassen. Daarom staat hier wat er echt draait, zodat je het kunt
        vergelijken met de gepubliceerde release op GitHub.
    </p>

    <div class="device-summary">
        <div class="panel panel-subtle">
            <strong>Versie</strong>
            <span><?= e(APP_VERSION) ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Manifest</strong>
            <span><?= $manifestHash ? e((string) ($manifest['version'] ?? '?')) : 'geen' ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Bestanden</strong>
            <span><?= count($rows) ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Afwijkend</strong>
            <span class="<?= $mismatches === 0 && $manifestHash ? 'verify-ok' : 'verify-bad' ?>"><?= $manifestHash ? $mismatches : 'n.v.t.' ?></span>
        </div>
    </div>

    <?php if ($manifestHash): ?>
        <div class="panel">
            <h2>Manifest op deze node</h2>
            <p>SHA-256 van <code>release-manifest.json</code>. Moet gelijk zijn aan het manifest in release <?= e((string) ($manifest['version'] ?? '')) ?> op GitHub.</p>
            <code class="fingerprint"><?= e($manifestHash) ?></code>
        </div>
    <?php else: ?>
        <div class="notice">Deze node heeft geen release-manifest. Dat is normaal voor een ontwikkelversie; een release hoort er een te hebben.</div>
    <?php endif; ?>

    <div class="panel">
        <h2>Zelf nameten</h2>
        <p>
            Open de devtools van je browser, tab Network, en bekijk <code>assets/js/crypto.js</code>.
            Het <code>integrity</code>-attribuut in de pagina en de hash hieronder moeten overeenkomen met het manifest.
            Stappen staan in <code>docs/verify.md</code> in de repository.
        </p>
    </div>

    <div class="panel">
        <h2>Bestanden</h2>
        <table class="verify-table">
            <thead>
                <tr><th>Bestand</th><th>SHA-256 (live)</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['path']) ?></td>
                        <td><code><?= e($row['sha256']) ?></code></td>
                        <td class="<?= $row['state'] === 'gelijk' ? 'verify-ok' : 'verify-bad' ?>"><?= e($row['state']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
