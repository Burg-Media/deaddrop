<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

$dropRef = $_GET['drop'] ?? '';

if ($dropRef === '') {
    redirect('dashboard.php');
}

$stmt = $pdo->prepare("
    SELECT
        d.public_id,
        d.name
    FROM deaddrops d
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE d.public_id = ?
      AND dm.user_id = ?
      AND dm.removed_at IS NULL
    LIMIT 1
");
$stmt->execute([$dropRef, (int) $currentUser['id']]);
$deaddrop = $stmt->fetch();

if (!$deaddrop) {
    http_response_code(403);
    exit('Geen toegang tot deze Deaddrop.');
}

$pageTitle = 'Bericht aangemaakt';

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <h1>Bericht aangemaakt</h1>

    <div class="panel">
        <h2>Encrypted drop staat klaar</h2>

        <p>
            Je bericht is lokaal versleuteld en opgeslagen voor de ontvanger binnen
            <strong><?= e($deaddrop['name']) ?></strong>.
        </p>

        <p>
            <small>
                De server heeft geen plaintext bericht en geen private pass ontvangen.
            </small>
        </p>
    </div>

    <div class="panel">
        <h2>Volgende actie</h2>

        <p>
            <a href="<?= APP_URL ?>/message_create.php?drop=<?= e($deaddrop['public_id']) ?>">
                Nog een bericht aanmaken
            </a>
        </p>

        <p>
            <a href="<?= APP_URL ?>/dashboard.php">
                Naar dashboard
            </a>
        </p>

        <p>
            <a href="<?= APP_URL ?>/logout.php">
                Uitloggen
            </a>
        </p>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
