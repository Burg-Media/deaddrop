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

$pageTitle = 'Admin Channels';

$error = $_SESSION['channel_create_error'] ?? null;
unset($_SESSION['channel_create_error']);

$stmt = $pdo->query("
    SELECT
        d.public_id,
        d.name,
        d.default_expiry_minutes,
        d.max_expiry_minutes,
        d.created_at,
        u.username AS owner_username,
        (
            SELECT COUNT(*)
            FROM deaddrop_members dm_count
            WHERE dm_count.deaddrop_id = d.id
              AND dm_count.removed_at IS NULL
        ) AS member_count
    FROM deaddrops d
    INNER JOIN users u ON u.id = d.owner_user_id
    ORDER BY d.created_at DESC
");
$channels = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/terminal.php">&larr; Terug naar terminal</a>
        |
        <a href="<?= APP_URL ?>/admin_users.php">Users beheren</a>
    </p>

    <h1>Admin Channels</h1>
    <p>Maak een nieuw channel aan. Jij wordt automatisch owner/member van het channel.</p>

    <?php if ($error): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Nieuw channel</h2>

        <form method="post" action="<?= APP_URL ?>/actions/create_channel.php">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="name">Naam</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="80"
                    required
                >
            </div>

            <div class="form-group">
                <label for="default_expiry_minutes">Default expiry</label>
                <select id="default_expiry_minutes" name="default_expiry_minutes" required>
                    <option value="15">15 minuten</option>
                    <option value="60">1 uur</option>
                    <option value="1440" selected>24 uur</option>
                    <option value="10080">7 dagen</option>
                </select>
            </div>

            <div class="form-group">
                <label for="max_expiry_minutes">Max expiry</label>
                <select id="max_expiry_minutes" name="max_expiry_minutes" required>
                    <option value="60">1 uur</option>
                    <option value="1440">24 uur</option>
                    <option value="10080" selected>7 dagen</option>
                </select>
            </div>

            <button type="submit">Channel aanmaken</button>
        </form>
    </div>

    <div class="panel">
        <h2>Bestaande channels</h2>

        <?php if (!$channels): ?>
            <p>Er zijn nog geen channels.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($channels as $channel): ?>
                    <li>
                        <a href="<?= APP_URL ?>/deaddrop.php?ref=<?= e($channel['public_id']) ?>">
                            <?= e($channel['name']) ?>
                        </a>
                        <small>
                            owner: <?= e($channel['owner_username']) ?>,
                            members: <?= (int) $channel['member_count'] ?>,
                            default: <?= (int) $channel['default_expiry_minutes'] ?>m,
                            max: <?= (int) $channel['max_expiry_minutes'] ?>m
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
