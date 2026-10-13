<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

require_superadmin($currentUser);

$pageTitle = 'Node Admin';

$countRows = static function (PDO $pdo, string $table, string $where = '1 = 1'): ?int {
    if (!db_table_exists($pdo, $table)) {
        return null;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return null;
    }

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}");

    return (int) ($stmt->fetch()['total'] ?? 0);
};

$migrationChecks = [
    'user_keys table' => db_table_exists($pdo, 'user_keys'),
    'trusted_devices table' => db_table_exists($pdo, 'trusted_devices'),
    'device_activation_codes table' => db_table_exists($pdo, 'device_activation_codes'),
    'users.deleted_at column' => db_column_exists($pdo, 'users', 'deleted_at'),
    'messages.salt column' => db_column_exists($pdo, 'messages', 'salt'),
    'auth_throttle table' => db_table_exists($pdo, 'auth_throttle'),
    'user_keys signing + kdf columns' => db_column_exists($pdo, 'user_keys', 'signing_public_key') && db_column_exists($pdo, 'user_keys', 'kdf_iterations'),
    'known_keys table' => db_table_exists($pdo, 'known_keys'),
    'messages.sender_user_id removed' => !db_column_exists($pdo, 'messages', 'sender_user_id'),
];

$publicRoot = realpath(__DIR__) ?: __DIR__;
$appRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: (string) ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown');
$httpsActive = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
$currentDevice = current_trusted_device($pdo);
$appKeySet = strlen(APP_KEY) >= 32;
$envInPublic = file_exists($publicRoot . '/.env');
$privateDirsInPublic = array_filter(['config', 'includes', 'database', 'tools', 'cron'], static function (string $dir) use ($publicRoot): bool {
    return is_dir($publicRoot . '/' . $dir);
});

$nodeFacts = [
    'Deaddrop version' => APP_VERSION,
    'PHP version' => PHP_VERSION,
    'Server time' => date('Y-m-d H:i:s T'),
    'APP_URL' => APP_URL,
    'HTTPS request' => $httpsActive ? 'yes' : 'no',
    'Secure cookies' => APP_COOKIE_SECURE ? 'yes' : 'no',
    'Device gate required' => device_trust_is_required($pdo) ? 'yes' : 'no',
    'APP_KEY configured' => $appKeySet ? 'yes' : 'missing',
    'Node action key configured' => node_action_key_is_configured() ? 'yes' : 'missing',
    'App root' => $appRoot,
    'Public root' => $publicRoot,
    'Apache document root' => $documentRoot,
];

$dataFacts = [
    'Active users' => $countRows($pdo, 'users', db_column_exists($pdo, 'users', 'deleted_at') ? 'deleted_at IS NULL' : '1 = 1'),
    'Superadmins' => $countRows($pdo, 'users', db_column_exists($pdo, 'users', 'deleted_at') ? "role = 'superadmin' AND deleted_at IS NULL" : "role = 'superadmin'"),
    'Admins' => $countRows($pdo, 'users', db_column_exists($pdo, 'users', 'deleted_at') ? "role = 'admin' AND deleted_at IS NULL" : "role = 'admin'"),
    'Channels' => $countRows($pdo, 'deaddrops'),
    'Unopened messages' => $countRows($pdo, 'messages', "status = 'unopened' AND expires_at > NOW()"),
    'Trusted devices' => $countRows($pdo, 'trusted_devices', "status = 'trusted'"),
    'Revoked devices' => $countRows($pdo, 'trusted_devices', "status = 'revoked'"),
    'Pending device codes' => $countRows($pdo, 'device_activation_codes', 'used_at IS NULL AND expires_at > NOW()'),
];

$safetyChecks = [
    'DocumentRoot points to public' => $documentRoot === $publicRoot,
    '.env outside public' => !$envInPublic,
    'private app dirs outside public' => !$privateDirsInPublic,
    'HTTPS for WebCrypto' => APP_COOKIE_SECURE ? $httpsActive : true,
    'APP_KEY set explicitly' => $appKeySet,
    'Node action key armed' => node_action_key_is_configured(),
    'Trusted current device' => $currentDevice !== null || !device_trust_is_required($pdo),
    'Release manifest present' => is_file(APP_ROOT . '/release-manifest.json'),
    'Sessions stored outside public' => is_dir(APP_ROOT . '/storage/sessions'),
];

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/terminal.php">&larr; Terug naar terminal</a>
        |
        <a href="<?= APP_URL ?>/node_identity.php">Identity provisioning</a>
    </p>

    <h1>Node Admin</h1>
    <p>Read-only node diagnostics voor superadmin. Deze pagina voert geen shell commands uit en toont geen secrets.</p>

    <div class="notice">
        Node Admin v1 is bewust read-only. Deploy, migrations, service restarts en `.env` wijzigingen blijven via SSH.
    </div>

    <div class="panel">
        <h2>Action key layer</h2>
        <p>
            Gevoelige node-acties mogen later alleen werken met superadmin login, trusted device,
            CSRF en een extra Node Action Key. Deze pagina toont alleen of die key-hash is ingesteld.
        </p>
        <small>
            Maak de hash via SSH met:
            <code>php tools/hash_node_action_key.php 'lange-random-node-action-key'</code>
            en zet de output in <code>NODE_ADMIN_ACTION_KEY_HASH</code>.
        </small>
    </div>

    <div class="device-summary">
        <div class="panel panel-subtle">
            <strong>Gate</strong>
            <span><?= device_trust_is_required($pdo) ? 'on' : 'off' ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>HTTPS</strong>
            <span><?= $httpsActive ? 'on' : 'off' ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Device</strong>
            <span><?= $currentDevice ? 'trusted' : 'none' ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Mode</strong>
            <span>read-only</span>
        </div>
    </div>

    <div class="panel">
        <h2>Node facts</h2>
        <ul>
            <?php foreach ($nodeFacts as $label => $value): ?>
                <li>
                    <strong><?= e($label) ?></strong>
                    <small><?= e((string) $value) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="panel">
        <h2>Data health</h2>
        <ul>
            <?php foreach ($dataFacts as $label => $value): ?>
                <li>
                    <strong><?= e($label) ?></strong>
                    <small><?= $value === null ? 'missing table' : (int) $value ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="panel">
        <h2>Migrations</h2>
        <div class="device-grid">
            <?php foreach ($migrationChecks as $label => $ok): ?>
                <article class="device-card">
                    <span class="status-pill <?= $ok ? 'status-trusted' : 'status-warning' ?>">
                        <?= $ok ? 'ok' : 'missing' ?>
                    </span>
                    <strong><?= e($label) ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <h2>Safety checks</h2>
        <div class="device-grid">
            <?php foreach ($safetyChecks as $label => $ok): ?>
                <article class="device-card">
                    <span class="status-pill <?= $ok ? 'status-trusted' : 'status-revoked' ?>">
                        <?= $ok ? 'pass' : 'check' ?>
                    </span>
                    <strong><?= e($label) ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
