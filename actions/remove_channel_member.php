<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$source = (string) ($_POST['source'] ?? '');
$deaddropRef = trim((string) ($_POST['deaddrop_ref'] ?? ''));
$memberRef = trim((string) ($_POST['member_ref'] ?? ''));
$confirmed = (string) ($_POST['confirm_remove'] ?? '') === '1';
$returnPath = $deaddropRef !== '' ? 'deaddrop.php?ref=' . urlencode($deaddropRef) : 'dashboard.php';

$setError = static function (string $message) use ($source, $returnPath): never {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = $message;
        redirect('terminal.php');
    }

    $_SESSION['channel_manage_error'] = $message;
    redirect($returnPath);
};

if ($deaddropRef === '' || $memberRef === '' || !$confirmed) {
    $setError('Bevestig eerst welke member je wilt verwijderen.');
}

$dropStmt = $pdo->prepare("
    SELECT
        d.id,
        d.name,
        dm.role AS current_member_role
    FROM deaddrops d
    LEFT JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
        AND dm.user_id = ?
        AND dm.removed_at IS NULL
    WHERE d.public_id = ?
    LIMIT 1
");
$dropStmt->execute([(int) $currentUser['id'], $deaddropRef]);
$deaddrop = $dropStmt->fetch();

if (!$deaddrop) {
    $setError('Channel niet gevonden.');
}

$canManage = is_admin($currentUser) || $deaddrop['current_member_role'] === 'owner';

if (!$canManage) {
    $setError('Je hebt geen rechten om members uit dit channel te verwijderen.');
}

$memberStmt = $pdo->prepare("
    SELECT
        dm.id,
        dm.role,
        u.id AS user_id,
        u.username
    FROM deaddrop_members dm
    INNER JOIN users u ON u.id = dm.user_id
    WHERE dm.deaddrop_id = ?
      AND u.public_id = ?
      AND dm.removed_at IS NULL
    LIMIT 1
");
$memberStmt->execute([(int) $deaddrop['id'], $memberRef]);
$targetMember = $memberStmt->fetch();

if (!$targetMember) {
    $setError('Member niet gevonden in dit channel.');
}

if ((int) $targetMember['user_id'] === (int) $currentUser['id']) {
    $setError('Je kunt jezelf niet via deze flow uit het channel verwijderen.');
}

if ($targetMember['role'] === 'owner') {
    $setError('Owners verwijderen of overdragen doen we niet via deze veilige MVP-flow.');
}

try {
    $remove = $pdo->prepare("
        UPDATE deaddrop_members
        SET removed_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $remove->execute([(int) $targetMember['id']]);
} catch (Throwable $e) {
    error_log('Channel member remove failed: ' . $e->getMessage());
    $setError('Member verwijderen is mislukt.');
}

if ($source === 'terminal') {
    $_SESSION['terminal_notice'] = $targetMember['username'] . ' verwijderd uit ' . $deaddrop['name'];
    $_SESSION['terminal_active_channel'] = $deaddropRef;
    redirect('terminal.php');
}

$_SESSION['channel_manage_notice'] = $targetMember['username'] . ' verwijderd uit ' . $deaddrop['name'];

redirect($returnPath);
