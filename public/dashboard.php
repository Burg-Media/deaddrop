<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

$pageTitle = 'Dashboard';

$stmt = $pdo->prepare("
    SELECT d.id, d.public_id, d.name, dm.role
    FROM deaddrops d
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE dm.user_id = ?
      AND dm.removed_at IS NULL
    ORDER BY d.created_at DESC
");
$stmt->execute([(int) $currentUser['id']]);
$deaddrops = $stmt->fetchAll();

$msgStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM messages
    WHERE recipient_user_id = ?
      AND status = 'unopened'
      AND expires_at > NOW()
");
$msgStmt->execute([(int) $currentUser['id']]);
$unopenedCount = (int) ($msgStmt->fetch()['total'] ?? 0);

$inboxStmt = $pdo->prepare("
    SELECT
        d.public_id,
        d.name,
        COUNT(m.id) AS total
    FROM messages m
    INNER JOIN deaddrops d ON d.id = m.deaddrop_id
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE m.recipient_user_id = ?
      AND dm.user_id = ?
      AND dm.removed_at IS NULL
      AND m.status = 'unopened'
      AND m.expires_at > NOW()
    GROUP BY d.id, d.public_id, d.name
    ORDER BY d.name ASC
");
$inboxStmt->execute([(int) $currentUser['id'], (int) $currentUser['id']]);
$inboxByChannel = $inboxStmt->fetchAll();
$unopenedByChannel = [];

foreach ($inboxByChannel as $channelInbox) {
    $unopenedByChannel[$channelInbox['public_id']] = (int) $channelInbox['total'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <h1>Dashboard</h1>
    <p>Ingelogd als <strong><?= e($currentUser['username']) ?></strong>.</p>

    <div class="panel">
        <h2>Terminal</h2>
        <p>Gebruik de terminal voor snelle channel-, member- en encrypted message-flows.</p>
        <p>
            <a href="<?= APP_URL ?>/terminal.php">Open terminal</a>
        </p>
    </div>

    <div class="panel">
        <h2>Inbox</h2>
        <p>Je hebt <strong><?= $unopenedCount ?></strong> ongeopende berichten.</p>

        <?php if ($inboxByChannel): ?>
            <ul>
                <?php foreach ($inboxByChannel as $channelInbox): ?>
                    <li>
                        <a href="<?= APP_URL ?>/deaddrop.php?ref=<?= e($channelInbox['public_id']) ?>">
                            <?= e($channelInbox['name']) ?>
                        </a>
                        <small>
                            <?= (int) $channelInbox['total'] ?> ongeopend
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p><small>Geen openstaande berichten per channel.</small></p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Mijn Deaddrops</h2>

        <?php if (!$deaddrops): ?>
            <p>Je zit nog in geen Deaddrops.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($deaddrops as $deaddrop): ?>
                    <li>
                        <a href="<?= APP_URL ?>/deaddrop.php?ref=<?= e($deaddrop['public_id']) ?>">
                            <?= e($deaddrop['name']) ?>
                        </a>
                        <small>
                            <?= e($deaddrop['role']) ?>,
                            <?= $unopenedByChannel[$deaddrop['public_id']] ?? 0 ?> ongeopend
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
