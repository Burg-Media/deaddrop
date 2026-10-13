<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

require_admin($currentUser);

$pageTitle = 'Admin Devices';
$hasDeviceTables = device_trust_tables_exist($pdo);
$error = $_SESSION['device_admin_error'] ?? null;
$notice = $_SESSION['device_admin_notice'] ?? null;
$activationCode = $_SESSION['device_activation_code'] ?? null;
unset($_SESSION['device_admin_error'], $_SESSION['device_admin_notice'], $_SESSION['device_activation_code']);

$devices = [];
$pendingCodes = [];
$recentCodes = [];
$users = [];
$currentDevice = null;
$deviceStats = [
    'trusted' => 0,
    'revoked' => 0,
    'pending_codes' => 0,
];

if ($hasDeviceTables) {
    $currentDevice = current_trusted_device($pdo);

    $devicesStmt = $pdo->query("
        SELECT
            td.public_id,
            td.user_id,
            td.label,
            td.status,
            td.activated_at,
            td.last_seen_at,
            td.revoked_at,
            u.username,
            u.public_id AS user_public_id
        FROM trusted_devices td
        LEFT JOIN users u ON u.id = td.user_id
        ORDER BY td.created_at DESC
    ");
    $devices = $devicesStmt->fetchAll();

    $pendingStmt = $pdo->query("
        SELECT
            dac.public_id,
            dac.label,
            dac.expires_at,
            dac.created_at,
            dac.used_at,
            u.username,
            u.public_id AS user_public_id,
            creator.username AS creator_username
        FROM device_activation_codes dac
        LEFT JOIN users u ON u.id = dac.user_id
        INNER JOIN users creator ON creator.id = dac.created_by_user_id
        WHERE dac.used_at IS NULL
          AND dac.expires_at > NOW()
        ORDER BY dac.created_at DESC
    ");
    $pendingCodes = $pendingStmt->fetchAll();

    $recentStmt = $pdo->query("
        SELECT
            dac.label,
            dac.expires_at,
            dac.created_at,
            dac.used_at,
            u.username,
            creator.username AS creator_username
        FROM device_activation_codes dac
        LEFT JOIN users u ON u.id = dac.user_id
        INNER JOIN users creator ON creator.id = dac.created_by_user_id
        ORDER BY dac.created_at DESC
        LIMIT 20
    ");
    $recentCodes = $recentStmt->fetchAll();

    $statsStmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM trusted_devices
        GROUP BY status
    ");
    foreach ($statsStmt->fetchAll() as $row) {
        $deviceStats[$row['status']] = (int) $row['total'];
    }

    $deviceStats['pending_codes'] = count($pendingCodes);

    $deletedFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'WHERE deleted_at IS NULL' : '';
    $usersStmt = $pdo->query("
        SELECT public_id, username, role
        FROM users
        {$deletedFilter}
        ORDER BY username ASC
    ");
    // Codes voor beheeraccounts maakt alleen een superadmin.
    $users = array_values(array_filter(
        $usersStmt->fetchAll(),
        static fn (array $user): bool => is_superadmin($currentUser) || !is_admin($user)
    ));
}

$formatTime = static function (?string $value): string {
    return $value ?: 'never';
};

$codeStatus = static function (array $code): string {
    if (!empty($code['used_at'])) {
        return 'used';
    }

    return strtotime((string) $code['expires_at']) <= time() ? 'expired' : 'pending';
};

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/admin_users.php">&larr; Terug naar users</a>
    </p>

    <h1>Admin Devices</h1>
    <p>Beheer trusted devices, activatiecodes en herstel devices waarvan de cookie kwijt of ongeldig is.</p>

    <?php if (!$hasDeviceTables): ?>
        <div class="notice error">
            Trusted device migratie ontbreekt: voer <code>database/2026_05_16_create_trusted_devices.sql</code> uit.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="notice">
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <?php if ($activationCode): ?>
        <div class="notice">
            <strong>Activatiecode:</strong>
            <code><?= e($activationCode) ?></code>
            <small>Toon deze code eenmalig aan de gebruiker. De server bewaart alleen de hash.</small>
        </div>
    <?php endif; ?>

    <?php if ($hasDeviceTables): ?>
        <div class="device-summary">
            <div class="panel panel-subtle">
                <strong>Gate</strong>
                <span><?= APP_DEVICE_TRUST_REQUIRED ? 'required' : 'not required' ?></span>
            </div>
            <div class="panel panel-subtle">
                <strong>Trusted</strong>
                <span><?= (int) $deviceStats['trusted'] ?></span>
            </div>
            <div class="panel panel-subtle">
                <strong>Pending codes</strong>
                <span><?= (int) $deviceStats['pending_codes'] ?></span>
            </div>
            <div class="panel panel-subtle">
                <strong>Revoked</strong>
                <span><?= (int) $deviceStats['revoked'] ?></span>
            </div>
        </div>

        <div class="panel">
            <h2>Huidig device</h2>

            <?php if ($currentDevice): ?>
                <p>
                    <span class="status-pill status-trusted">trusted</span>
                    <strong><?= e($currentDevice['label']) ?></strong>
                </p>
                <small>
                    binding:
                    <?= $currentDevice['user_id'] !== null ? 'user-bound' : 'any active user' ?>
                </small>
            <?php else: ?>
                <p>
                    <span class="status-pill status-warning">not trusted</span>
                    Dit browserprofiel heeft geen geldige trusted-device cookie.
                </p>
                <small>
                    Als de gate aan staat, wordt dit device naar activatie gestuurd voordat login mogelijk is.
                </small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Nieuwe activatiecode</h2>

        <form method="post" action="<?= APP_URL ?>/actions/create_device_activation.php" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="label">Device label</label>
                <input
                    type="text"
                    id="label"
                    name="label"
                    maxlength="120"
                    placeholder="Pixel 7 - gebruiker"
                    required
                >
            </div>

            <div class="form-group">
                <label for="user_ref">Koppel aan user</label>
                <select id="user_ref" name="user_ref">
                    <?php if (is_superadmin($currentUser)): ?>
                        <option value="">Geen vaste user</option>
                    <?php endif; ?>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= e($user['public_id']) ?>">
                            <?= e($user['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="expires_hours">Code verloopt na</label>
                <select id="expires_hours" name="expires_hours" required>
                    <option value="1">1 uur</option>
                    <option value="8">8 uur</option>
                    <option value="24" selected>24 uur</option>
                    <option value="72">72 uur</option>
                </select>
            </div>

            <button type="submit">Activatiecode maken</button>
        </form>
    </div>

    <div class="panel">
        <h2>Pending activatiecodes</h2>

        <?php if (!$pendingCodes): ?>
            <p>Geen actieve activatiecodes.</p>
        <?php else: ?>
            <div class="device-grid">
                <?php foreach ($pendingCodes as $code): ?>
                    <article class="device-card">
                        <span class="status-pill status-pending">pending</span>
                        <strong><?= e($code['label']) ?></strong>
                        <small>
                            user: <?= e($code['username'] ?? 'any') ?><br>
                            expires: <?= e($code['expires_at']) ?><br>
                            created by: <?= e($code['creator_username']) ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Trusted devices</h2>

        <?php if (!$devices): ?>
            <p>Geen trusted devices.</p>
        <?php else: ?>
            <div class="device-grid">
                <?php foreach ($devices as $device): ?>
                    <article class="device-card">
                        <span class="status-pill <?= $device['status'] === 'trusted' ? 'status-trusted' : 'status-revoked' ?>">
                            <?= e($device['status']) ?>
                        </span>
                        <strong><?= e($device['label']) ?></strong>
                        <small>
                            binding: <?= e($device['username'] ?? 'any active user') ?><br>
                            activated: <?= e($formatTime($device['activated_at'])) ?><br>
                            last seen: <?= e($formatTime($device['last_seen_at'])) ?>
                            <?php if ($device['revoked_at']): ?>
                                <br>revoked: <?= e($formatTime($device['revoked_at'])) ?>
                            <?php endif; ?>
                        </small>

                        <div class="actions-row">
                            <form method="post" action="<?= APP_URL ?>/actions/create_device_activation.php" autocomplete="off">
                                <?= csrf_field() ?>
                                <input type="hidden" name="label" value="<?= e($device['label']) ?> replacement">
                                <input type="hidden" name="user_ref" value="<?= e((string) ($device['user_public_id'] ?? '')) ?>">
                                <input type="hidden" name="expires_hours" value="24">
                                <button type="submit">Nieuwe code</button>
                            </form>

                        <?php if ($device['status'] !== 'revoked'): ?>
                            <form method="post" action="<?= APP_URL ?>/actions/revoke_device.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="device_ref" value="<?= e($device['public_id']) ?>">
                                <label>
                                    <input type="checkbox" name="confirm_revoke" value="1" required>
                                    Revoke
                                </label>
                                <button type="submit">Revoke</button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Recente activatiecodes</h2>

        <?php if (!$recentCodes): ?>
            <p>Geen activatiecodes aangemaakt.</p>
        <?php else: ?>
            <div class="device-grid">
                <?php foreach ($recentCodes as $code): ?>
                    <?php $status = $codeStatus($code); ?>
                    <article class="device-card">
                        <span class="status-pill status-<?= e($status) ?>"><?= e($status) ?></span>
                        <strong><?= e($code['label']) ?></strong>
                        <small>
                            user: <?= e($code['username'] ?? 'any') ?><br>
                            created: <?= e($formatTime($code['created_at'])) ?><br>
                            expires: <?= e($formatTime($code['expires_at'])) ?><br>
                            created by: <?= e($code['creator_username']) ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
