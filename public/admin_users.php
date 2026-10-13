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

$pageTitle = 'Admin Users';
$hasSoftDelete = db_column_exists($pdo, 'users', 'deleted_at')
    && db_column_exists($pdo, 'users', 'deleted_by_user_id');

$createError = $_SESSION['user_create_error'] ?? null;
$createNotice = $_SESSION['user_create_notice'] ?? null;
$deleteError = $_SESSION['user_delete_error'] ?? null;
$deleteNotice = $_SESSION['user_delete_notice'] ?? null;
unset(
    $_SESSION['user_create_error'],
    $_SESSION['user_create_notice'],
    $_SESSION['user_delete_error'],
    $_SESSION['user_delete_notice']
);

$deletedSelect = $hasSoftDelete ? 'u.deleted_at' : 'NULL AS deleted_at';
$deletedOrder = $hasSoftDelete ? 'u.deleted_at' : 'NULL';

$stmt = $pdo->query("
    SELECT
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
        u.created_at DESC
");
$users = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/admin_channels.php">&larr; Terug naar admin channels</a>
        |
        <a href="<?= APP_URL ?>/admin_devices.php">Devices beheren</a>
    </p>

    <h1>Admin Users</h1>
    <p>Maak normale users aan en deactiveer accounts zonder historische data hard te verwijderen.</p>

    <?php if (!$hasSoftDelete): ?>
        <div class="notice error">
            Database-migratie ontbreekt: voer <code>database/2026_05_15_add_user_soft_delete.sql</code> uit voordat je users deactiveert.
        </div>
    <?php endif; ?>

    <?php foreach ([$createError, $deleteError] as $error): ?>
        <?php if ($error): ?>
            <div class="notice error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php foreach ([$createNotice, $deleteNotice] as $notice): ?>
        <?php if ($notice): ?>
            <div class="notice">
                <?= e($notice) ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="panel">
        <h2>Nieuwe user</h2>

        <form method="post" action="<?= APP_URL ?>/actions/create_user.php" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    minlength="3"
                    maxlength="50"
                    pattern="[a-zA-Z0-9._-]{3,50}"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Tijdelijk wachtwoord</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >
            </div>

            <p>
                <small>
                    Nieuwe users krijgen role <strong>member</strong>. Ze moeten na login zelf hun private pass/key setup afronden.
                </small>
            </p>

            <button type="submit">User aanmaken</button>
        </form>
    </div>

    <div class="panel">
        <h2>Users</h2>

        <?php if (!$users): ?>
            <p>Er zijn nog geen users.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($users as $user): ?>
                    <?php
                    $isDeleted = $user['deleted_at'] !== null;
                    $isCurrentUser = $user['public_id'] === $currentUser['public_id'];
                    ?>
                    <li>
                        <strong><?= e($user['username']) ?></strong>
                        <small>
                            role: <?= e($user['role']) ?>,
                            key: <?= $user['key_id'] ? 'active' : 'missing' ?>,
                            channels: <?= (int) $user['channel_count'] ?>,
                            created: <?= e((string) $user['created_at']) ?>,
                            last login: <?= e((string) ($user['last_login_at'] ?? 'never')) ?>
                            <?php if ($isDeleted): ?>
                                , status: deleted at <?= e((string) $user['deleted_at']) ?>
                            <?php else: ?>
                                , status: active
                            <?php endif; ?>
                        </small>

                        <?php if ($hasSoftDelete && !$isDeleted && !$isCurrentUser): ?>
                            <form method="post" action="<?= APP_URL ?>/actions/delete_user.php" class="form-spaced">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_ref" value="<?= e($user['public_id']) ?>">
                                <label>
                                    <input type="checkbox" name="confirm_delete" value="1" required>
                                    Deactiveer <?= e($user['username']) ?>
                                </label>
                                <button type="submit">User deactiveren</button>
                            </form>
                        <?php elseif ($isCurrentUser): ?>
                            <small>Je eigen account kan hier niet worden gedeactiveerd.</small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
