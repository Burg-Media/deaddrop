<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

$ref = $_GET['ref'] ?? '';

if ($ref === '') {
    http_response_code(404);
    exit('Deaddrop niet gevonden.');
}

/**
 * Check of deze user lid is van deze Deaddrop.
 */
$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.public_id,
        d.name,
        d.owner_user_id,
        d.default_expiry_minutes,
        d.max_expiry_minutes,
        d.created_at,
        dm.role AS member_role
    FROM deaddrops d
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE d.public_id = ?
      AND dm.user_id = ?
      AND dm.removed_at IS NULL
    LIMIT 1
");
$stmt->execute([$ref, (int) $currentUser['id']]);
$deaddrop = $stmt->fetch();

if (!$deaddrop) {
    http_response_code(403);
    exit('Geen toegang tot deze Deaddrop.');
}

/**
 * Leden ophalen.
 */
$activeUserFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'AND u.deleted_at IS NULL' : '';

$membersStmt = $pdo->prepare("
    SELECT 
        u.public_id,
        u.username,
        dm.role,
        dm.created_at
    FROM deaddrop_members dm
    INNER JOIN users u ON u.id = dm.user_id
    WHERE dm.deaddrop_id = ?
      AND dm.removed_at IS NULL
      {$activeUserFilter}
    ORDER BY 
        CASE dm.role WHEN 'owner' THEN 0 ELSE 1 END,
        u.username ASC
");
$membersStmt->execute([(int) $deaddrop['id']]);
$members = $membersStmt->fetchAll();

$canManageMembers = is_admin($currentUser) || $deaddrop['member_role'] === 'owner';
$availableUsers = [];

if ($canManageMembers) {
    $availableUsersStmt = $pdo->prepare("
        SELECT u.username
        FROM users u
        LEFT JOIN deaddrop_members dm ON dm.user_id = u.id
            AND dm.deaddrop_id = ?
            AND dm.removed_at IS NULL
        WHERE dm.id IS NULL
          AND u.id != ?
          {$activeUserFilter}
        ORDER BY u.username ASC
    ");
    $availableUsersStmt->execute([(int) $deaddrop['id'], (int) $currentUser['id']]);
    $availableUsers = $availableUsersStmt->fetchAll();
}

/**
 * Ontvangen berichten voor deze user binnen deze Deaddrop.
 */
$messagesStmt = $pdo->prepare("
    SELECT 
        public_id,
        status,
        created_bucket,
        expires_at,
        opened_at,
        destroyed_at,
        failed_attempts
    FROM messages
    WHERE deaddrop_id = ?
      AND recipient_user_id = ?
      AND status = 'unopened'
      AND expires_at > NOW()
    ORDER BY created_at DESC, id DESC
");
$messagesStmt->execute([(int) $deaddrop['id'], (int) $currentUser['id']]);
$messages = $messagesStmt->fetchAll();

$pageTitle = $deaddrop['name'];
$memberAddNotice = $_SESSION['member_add_notice'] ?? null;
$memberAddError = $_SESSION['member_add_error'] ?? null;
$channelManageNotice = $_SESSION['channel_manage_notice'] ?? null;
$channelManageError = $_SESSION['channel_manage_error'] ?? null;
unset(
    $_SESSION['member_add_notice'],
    $_SESSION['member_add_error'],
    $_SESSION['channel_manage_notice'],
    $_SESSION['channel_manage_error']
);

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/dashboard.php">&larr; Terug naar dashboard</a>
    </p>

    <h1><?= e($deaddrop['name']) ?></h1>

    <p>
        Jouw rol:
        <strong><?= e($deaddrop['member_role']) ?></strong>
    </p>

    <?php if ($memberAddNotice): ?>
        <div class="notice">
            <?= e($memberAddNotice) ?>
        </div>
    <?php endif; ?>

    <?php if ($channelManageNotice): ?>
        <div class="notice">
            <?= e($channelManageNotice) ?>
        </div>
    <?php endif; ?>

    <?php if ($memberAddError): ?>
        <div class="notice error">
            <?= e($memberAddError) ?>
        </div>
    <?php endif; ?>

    <?php if ($channelManageError): ?>
        <div class="notice error">
            <?= e($channelManageError) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Acties</h2>

        <p>
            <a href="<?= APP_URL ?>/message_create.php?drop=<?= e($deaddrop['public_id']) ?>">
                Nieuw bericht maken
            </a>
        </p>
    </div>

    <div class="panel">
        <h2>Mijn inbox binnen deze Deaddrop</h2>

        <?php if (!$messages): ?>
            <p>Er staan geen ongeopende berichten voor je klaar.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($messages as $message): ?>
                    <li>
                        <strong>Verzegeld bericht</strong>
                        <small>status: <?= e($message['status']) ?> · verloopt: <?= e(date('d-m-Y H:i', strtotime((string) $message['expires_at']))) ?></small>

                        <br>

                        <a href="<?= APP_URL ?>/message_open.php?ref=<?= e($message['public_id']) ?>">
                            Open bericht
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($canManageMembers): ?>
        <div class="panel">
            <h2>Channel instellingen</h2>

            <form method="post" action="<?= APP_URL ?>/actions/update_channel.php">
                <?= csrf_field() ?>
                <input type="hidden" name="source" value="deaddrop">
                <input type="hidden" name="deaddrop_ref" value="<?= e($deaddrop['public_id']) ?>">

                <div class="form-group">
                    <label for="channel_name">Naam</label>
                    <input
                        type="text"
                        id="channel_name"
                        name="name"
                        value="<?= e($deaddrop['name']) ?>"
                        minlength="3"
                        maxlength="80"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="default_expiry_minutes">Default expiry</label>
                    <select id="default_expiry_minutes" name="default_expiry_minutes" required>
                        <?php foreach ([15 => '15 minuten', 60 => '1 uur', 1440 => '24 uur', 10080 => '7 dagen'] as $minutes => $label): ?>
                            <option value="<?= $minutes ?>" <?= (int) $deaddrop['default_expiry_minutes'] === $minutes ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="max_expiry_minutes">Max expiry</label>
                    <select id="max_expiry_minutes" name="max_expiry_minutes" required>
                        <?php foreach ([60 => '1 uur', 1440 => '24 uur', 10080 => '7 dagen'] as $minutes => $label): ?>
                            <option value="<?= $minutes ?>" <?= (int) $deaddrop['max_expiry_minutes'] === $minutes ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Channel bijwerken</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Leden</h2>

        <?php if ($canManageMembers): ?>
            <form method="post" action="<?= APP_URL ?>/actions/add_channel_member.php">
                <?= csrf_field() ?>
                <input type="hidden" name="source" value="deaddrop">
                <input type="hidden" name="deaddrop_ref" value="<?= e($deaddrop['public_id']) ?>">

                <div class="form-group">
                    <label for="member_username">Member toevoegen</label>
                    <input
                        type="text"
                        id="member_username"
                        name="username"
                        list="available_users"
                        autocomplete="off"
                        required
                    >

                    <?php if ($availableUsers): ?>
                        <datalist id="available_users">
                            <?php foreach ($availableUsers as $availableUser): ?>
                                <option value="<?= e($availableUser['username']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    <?php endif; ?>
                </div>

                <button type="submit">Member toevoegen</button>
            </form>
        <?php endif; ?>

        <?php if (!$members): ?>
            <p>Geen leden gevonden.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($members as $member): ?>
                    <li>
                        <?= e($member['username']) ?>
                        <small>(<?= e($member['role']) ?>)</small>

                        <?php if (
                            $canManageMembers &&
                            $member['role'] !== 'owner' &&
                            $member['public_id'] !== $currentUser['public_id']
                        ): ?>
                            <form method="post" action="<?= APP_URL ?>/actions/remove_channel_member.php" class="form-spaced">
                                <?= csrf_field() ?>
                                <input type="hidden" name="source" value="deaddrop">
                                <input type="hidden" name="deaddrop_ref" value="<?= e($deaddrop['public_id']) ?>">
                                <input type="hidden" name="member_ref" value="<?= e($member['public_id']) ?>">
                                <label>
                                    <input type="checkbox" name="confirm_remove" value="1" required>
                                    Verwijder <?= e($member['username']) ?> uit dit channel
                                </label>
                                <button type="submit">Member verwijderen</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
