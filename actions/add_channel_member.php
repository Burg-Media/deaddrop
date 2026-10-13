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
    redirect('terminal.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$deaddropRef = trim($_POST['deaddrop_ref'] ?? '');
$username = trim($_POST['username'] ?? '');
$source = $_POST['source'] ?? '';
$returnPath = 'terminal.php';

if ($deaddropRef !== '' && $source === 'deaddrop') {
    $returnPath = 'deaddrop.php?ref=' . urlencode($deaddropRef);
}

if ($deaddropRef === '' || $username === '') {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'Vul een username in.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'Gebruik: add member <username>';
    redirect($returnPath);
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
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'Channel niet gevonden.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'Channel niet gevonden.';
    redirect($returnPath);
}

$canManage = is_admin($currentUser) || $deaddrop['current_member_role'] === 'owner';

if (!$canManage) {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'Je hebt geen rechten om members toe te voegen.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'Je hebt geen rechten om members toe te voegen.';
    redirect($returnPath);
}

$activeUserFilter = db_column_exists($pdo, 'users', 'deleted_at') ? ' AND deleted_at IS NULL' : '';

$userStmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ?' . $activeUserFilter . ' LIMIT 1');
$userStmt->execute([$username]);
$targetUser = $userStmt->fetch();

if (!$targetUser) {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'User niet gevonden: ' . $username;
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'User niet gevonden: ' . $username;
    redirect($returnPath);
}

if ((int) $targetUser['id'] === (int) $currentUser['id']) {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'Je bent al lid van dit channel.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'Je bent al lid van dit channel.';
    redirect($returnPath);
}

$memberStmt = $pdo->prepare("
    SELECT id, removed_at
    FROM deaddrop_members
    WHERE deaddrop_id = ?
      AND user_id = ?
    LIMIT 1
");
$memberStmt->execute([(int) $deaddrop['id'], (int) $targetUser['id']]);
$existingMember = $memberStmt->fetch();

if ($existingMember && $existingMember['removed_at'] === null) {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = $targetUser['username'] . ' is al lid van dit channel.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = $targetUser['username'] . ' is al lid van dit channel.';
    redirect($returnPath);
}

try {
    if ($existingMember) {
        $update = $pdo->prepare("
            UPDATE deaddrop_members
            SET role = 'member',
                removed_at = NULL,
                created_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $update->execute([(int) $existingMember['id']]);
    } else {
        $insert = $pdo->prepare("
            INSERT INTO deaddrop_members (
                deaddrop_id,
                user_id,
                role,
                created_at
            ) VALUES (
                ?,
                ?,
                'member',
                NOW()
            )
        ");
        $insert->execute([(int) $deaddrop['id'], (int) $targetUser['id']]);
    }
} catch (Throwable $e) {
    if ($source === 'deaddrop') {
        $_SESSION['member_add_error'] = 'Member toevoegen is mislukt.';
        redirect($returnPath);
    }

    $_SESSION['terminal_error'] = 'Member toevoegen is mislukt.';
    redirect($returnPath);
}

if ($source === 'deaddrop') {
    $_SESSION['member_add_notice'] = $targetUser['username'] . ' toegevoegd aan ' . $deaddrop['name'];
    redirect($returnPath);
}

$_SESSION['terminal_notice'] = $targetUser['username'] . ' toegevoegd aan ' . $deaddrop['name'];
$_SESSION['terminal_active_channel'] = $deaddropRef;

redirect($returnPath);
