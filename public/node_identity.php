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

$pageTitle = 'Node Identity';
$hasSoftDelete = db_column_exists($pdo, 'users', 'deleted_at');
$hasDeviceTables = device_trust_tables_exist($pdo);

$deletedSelect = $hasSoftDelete ? 'u.deleted_at' : 'NULL AS deleted_at';
$deletedOrder = $hasSoftDelete ? 'u.deleted_at' : 'NULL';
$deletedFilter = $hasSoftDelete ? 'AND u.deleted_at IS NULL' : '';

$usersStmt = $pdo->query("
    SELECT
        u.id,
        u.public_id,
        u.username,
        u.role,
        u.created_at,
        u.last_login_at,
        {$deletedSelect},
        uk.id AS key_id,
        (
            SELECT COUNT(*)
            FROM deaddrop_members dm
            WHERE dm.user_id = u.id
              AND dm.removed_at IS NULL
        ) AS channel_count
    FROM users u
    LEFT JOIN user_keys uk ON uk.user_id = u.id
    ORDER BY
        CASE WHEN {$deletedOrder} IS NULL THEN 0 ELSE 1 END,
        CASE u.role WHEN 'superadmin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END,
        u.username ASC
");
$users = $usersStmt->fetchAll();

$devicesByUser = [];
$pendingCodesByUser = [];
$channelNamesByUser = [];

if ($hasDeviceTables) {
    $devicesStmt = $pdo->query("
        SELECT
            td.user_id,
            td.label,
            td.status,
            td.activated_at,
            td.last_seen_at,
            td.revoked_at
        FROM trusted_devices td
        WHERE td.user_id IS NOT NULL
        ORDER BY td.created_at DESC
    ");

    foreach ($devicesStmt->fetchAll() as $device) {
        $devicesByUser[(int) $device['user_id']][] = $device;
    }

    $pendingStmt = $pdo->query("
        SELECT
            dac.user_id,
            dac.label,
            dac.expires_at,
            dac.created_at
        FROM device_activation_codes dac
        WHERE dac.user_id IS NOT NULL
          AND dac.used_at IS NULL
          AND dac.expires_at > NOW()
        ORDER BY dac.created_at DESC
    ");

    foreach ($pendingStmt->fetchAll() as $code) {
        $pendingCodesByUser[(int) $code['user_id']][] = $code;
    }
}

$channelsStmt = $pdo->query("
    SELECT
        dm.user_id,
        d.name,
        dm.role
    FROM deaddrop_members dm
    INNER JOIN deaddrops d ON d.id = dm.deaddrop_id
    INNER JOIN users u ON u.id = dm.user_id
    WHERE dm.removed_at IS NULL
      {$deletedFilter}
    ORDER BY d.name ASC
");

foreach ($channelsStmt->fetchAll() as $channel) {
    $channelNamesByUser[(int) $channel['user_id']][] = $channel['name'] . ' (' . $channel['role'] . ')';
}

$activeUsers = 0;
$keyReadyUsers = 0;
$usersWithTrustedDevice = 0;
$usersWithPendingCode = 0;

foreach ($users as $user) {
    $isDeleted = $user['deleted_at'] !== null;

    if (!$isDeleted) {
        $activeUsers++;
    }

    if ($user['key_id'] !== null) {
        $keyReadyUsers++;
    }

    $trustedDevices = array_filter($devicesByUser[(int) $user['id']] ?? [], static function (array $device): bool {
        return $device['status'] === 'trusted';
    });

    if ($trustedDevices) {
        $usersWithTrustedDevice++;
    }

    if (!empty($pendingCodesByUser[(int) $user['id']])) {
        $usersWithPendingCode++;
    }
}

$formatTime = static function (?string $value): string {
    return $value ?: 'never';
};

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/node_admin.php">&larr; Terug naar Node Admin</a>
        |
        <a href="<?= APP_URL ?>/admin_users.php">User actions</a>
        |
        <a href="<?= APP_URL ?>/admin_devices.php">Device actions</a>
    </p>

    <h1>Node Identity</h1>
    <p>Superadmin provisioning-overzicht voor users, E2EE readiness, trusted devices en channel exposure.</p>

    <div class="notice">
        Deze pagina is bewust read-heavy. User/device mutaties lopen nog via bestaande veilige admin-actions.
    </div>

    <div class="device-summary">
        <div class="panel panel-subtle">
            <strong>Active users</strong>
            <span><?= $activeUsers ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>E2EE ready</strong>
            <span><?= $keyReadyUsers ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Trusted device</strong>
            <span><?= $usersWithTrustedDevice ?></span>
        </div>
        <div class="panel panel-subtle">
            <strong>Pending code</strong>
            <span><?= $usersWithPendingCode ?></span>
        </div>
    </div>

    <?php if (!$hasDeviceTables): ?>
        <div class="notice error">
            Trusted device migratie ontbreekt. Device provisioning status is nog niet beschikbaar.
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Identity lifecycle</h2>

        <?php if (!$users): ?>
            <p>Geen users gevonden.</p>
        <?php else: ?>
            <div class="identity-list">
                <?php foreach ($users as $user): ?>
                    <?php
                    $userId = (int) $user['id'];
                    $isDeleted = $user['deleted_at'] !== null;
                    $devices = $devicesByUser[$userId] ?? [];
                    $trustedDevices = array_filter($devices, static function (array $device): bool {
                        return $device['status'] === 'trusted';
                    });
                    $pendingCodes = $pendingCodesByUser[$userId] ?? [];
                    $channels = $channelNamesByUser[$userId] ?? [];
                    $isReady = !$isDeleted && $user['key_id'] !== null && (bool) $trustedDevices;
                    ?>

                    <article class="identity-card">
                        <div>
                            <span class="status-pill <?= $isReady ? 'status-trusted' : ($isDeleted ? 'status-revoked' : 'status-warning') ?>">
                                <?= $isReady ? 'ready' : ($isDeleted ? 'disabled' : 'needs setup') ?>
                            </span>
                            <strong><?= e($user['username']) ?></strong>
                            <small>
                                role: <?= e($user['role']) ?><br>
                                public id: <?= e(substr((string) $user['public_id'], 0, 16)) ?>...<br>
                                created: <?= e($formatTime($user['created_at'])) ?><br>
                                last login: <?= e($formatTime($user['last_login_at'])) ?>
                            </small>
                        </div>

                        <div>
                            <strong>Provisioning</strong>
                            <small>
                                account: <?= $isDeleted ? 'disabled' : 'active' ?><br>
                                E2EE keys: <?= $user['key_id'] ? 'ready' : 'missing' ?><br>
                                trusted devices: <?= count($trustedDevices) ?><br>
                                pending codes: <?= count($pendingCodes) ?><br>
                                channels: <?= (int) $user['channel_count'] ?>
                            </small>
                        </div>

                        <div>
                            <strong>Devices</strong>
                            <?php if (!$devices): ?>
                                <small>Geen user-bound devices.</small>
                            <?php else: ?>
                                <small>
                                    <?php foreach (array_slice($devices, 0, 4) as $device): ?>
                                        <?= e($device['label']) ?>
                                        (<?= e($device['status']) ?>,
                                        last seen: <?= e($formatTime($device['last_seen_at'])) ?>)<br>
                                    <?php endforeach; ?>
                                    <?php if (count($devices) > 4): ?>
                                        + <?= count($devices) - 4 ?> meer
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div>
                            <strong>Channels</strong>
                            <?php if (!$channels): ?>
                                <small>Geen channel memberships.</small>
                            <?php else: ?>
                                <small>
                                    <?= e(implode(', ', array_slice($channels, 0, 5))) ?>
                                    <?php if (count($channels) > 5): ?>
                                        , +<?= count($channels) - 5 ?> meer
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="actions-row">
                            <?php if ($hasDeviceTables && !$isDeleted): ?>
                            <form method="post" action="<?= APP_URL ?>/actions/create_device_activation.php" autocomplete="off">
                                <?= csrf_field() ?>
                                <input type="hidden" name="label" value="<?= e($user['username']) ?> device">
                                <input type="hidden" name="user_ref" value="<?= e($user['public_id']) ?>">
                                <input type="hidden" name="expires_hours" value="24">
                                <button type="submit">Device code</button>
                            </form>
                            <?php endif; ?>

                            <a href="<?= APP_URL ?>/admin_users.php">User beheer</a>
                            <a href="<?= APP_URL ?>/admin_devices.php">Device beheer</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
