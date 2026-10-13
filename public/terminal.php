<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

$pageTitle = 'Terminal';
$terminalPage = true;

$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.public_id,
        d.name,
        d.default_expiry_minutes,
        d.max_expiry_minutes,
        dm.role,
        (
            SELECT COUNT(*)
            FROM deaddrop_members dm_count
            WHERE dm_count.deaddrop_id = d.id
              AND dm_count.removed_at IS NULL
        ) AS member_count
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

$messagesStmt = $pdo->prepare("
    SELECT
        m.public_id,
        m.deaddrop_id,
        m.status,
        m.created_bucket,
        m.expires_at,
        m.failed_attempts,
        d.public_id AS deaddrop_public_id,
        d.name AS deaddrop_name
    FROM messages m
    INNER JOIN deaddrops d ON d.id = m.deaddrop_id
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE m.recipient_user_id = ?
      AND dm.user_id = ?
      AND dm.removed_at IS NULL
      AND m.status = 'unopened'
      AND m.expires_at > NOW()
    ORDER BY m.created_at DESC, m.id DESC
");
$messagesStmt->execute([(int) $currentUser['id'], (int) $currentUser['id']]);
$messages = $messagesStmt->fetchAll();

$membersByDrop = [];
$canManageAnyChannel = is_admin($currentUser);
$activeUserFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'AND u.deleted_at IS NULL' : '';

if ($deaddrops) {
    $memberStmt = $pdo->prepare("
        SELECT
            u.username,
            u.public_id,
            dm.role,
            dm.created_at,
            uk.id AS key_id
        FROM deaddrop_members dm
        INNER JOIN users u ON u.id = dm.user_id
        LEFT JOIN user_keys uk ON uk.user_id = u.id
        WHERE dm.deaddrop_id = ?
          AND dm.removed_at IS NULL
          {$activeUserFilter}
        ORDER BY
            CASE dm.role WHEN 'owner' THEN 0 ELSE 1 END,
            u.username ASC
    ");

    foreach ($deaddrops as $deaddrop) {
        if ($deaddrop['role'] === 'owner') {
            $canManageAnyChannel = true;
        }

        $memberStmt->execute([(int) $deaddrop['id']]);
        $membersByDrop[$deaddrop['public_id']] = array_map(static function (array $member): array {
            return [
                'username' => $member['username'],
                'publicId' => $member['public_id'],
                'role' => $member['role'],
                'created' => $member['created_at'],
                'hasKey' => $member['key_id'] !== null,
            ];
        }, $memberStmt->fetchAll());
    }
}

$availableUsers = [];
$deviceUsers = [];

if ($canManageAnyChannel) {
    $usersStmt = $pdo->prepare("
        SELECT
            u.public_id,
            u.username,
            u.role,
            uk.id AS key_id
        FROM users u
        LEFT JOIN user_keys uk ON uk.user_id = u.id
        WHERE u.id != ?
          {$activeUserFilter}
        ORDER BY username ASC
    ");
    $usersStmt->execute([(int) $currentUser['id']]);
    $availableUsers = array_map(static function (array $user): array {
        return [
            'publicId' => $user['public_id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'hasKey' => $user['key_id'] !== null,
        ];
    }, $usersStmt->fetchAll());
}

if (is_admin($currentUser)) {
    $deviceUsersStmt = $pdo->query("
        SELECT
            u.public_id,
            u.username,
            u.role,
            uk.id AS key_id
        FROM users u
        LEFT JOIN user_keys uk ON uk.user_id = u.id
        WHERE 1 = 1
          {$activeUserFilter}
        ORDER BY username ASC
    ");
    $deviceUsers = array_values(array_map(static function (array $user): array {
        return [
            'publicId' => $user['public_id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'hasKey' => $user['key_id'] !== null,
        ];
    }, array_filter(
        $deviceUsersStmt->fetchAll(),
        static fn (array $user): bool => is_superadmin($currentUser) || !is_admin($user)
    )));
}

$terminalNotice = $_SESSION['terminal_notice'] ?? null;
$terminalError = $_SESSION['terminal_error'] ?? null;
$terminalActiveChannel = $_SESSION['terminal_active_channel'] ?? null;
unset($_SESSION['terminal_notice'], $_SESSION['terminal_error']);

$visibleChannelIds = array_column($deaddrops, 'public_id');
$activeChannel = in_array($terminalActiveChannel, $visibleChannelIds, true)
    ? $terminalActiveChannel
    : ($visibleChannelIds[0] ?? '');
$currentDevice = current_trusted_device($pdo);

$terminalState = [
    'appUrl' => APP_URL,
    'deviceTrustRequired' => device_trust_is_required($pdo),
    'trustedDevice' => $currentDevice ? [
        'label' => $currentDevice['label'],
        'publicId' => $currentDevice['public_id'],
        'userBound' => $currentDevice['user_id'] !== null,
    ] : null,
    'activeChannel' => $activeChannel,
    'user' => [
        'username' => $currentUser['username'],
        'publicId' => $currentUser['public_id'],
        'role' => $currentUser['role'],
    ],
    'csrfToken' => csrf_token(),
    'notice' => $terminalNotice,
    'error' => $terminalError,
    'unopenedCount' => $unopenedCount,
    'availableUsers' => $availableUsers,
    'deviceUsers' => $deviceUsers,
    'channels' => array_map(static function (array $deaddrop) use ($membersByDrop): array {
        return [
            'id' => $deaddrop['public_id'],
            'label' => $deaddrop['name'],
            'role' => $deaddrop['role'],
            'defaultExpiryMinutes' => (int) $deaddrop['default_expiry_minutes'],
            'maxExpiryMinutes' => (int) $deaddrop['max_expiry_minutes'],
            'members' => (int) $deaddrop['member_count'],
            'memberList' => $membersByDrop[$deaddrop['public_id']] ?? [],
        ];
    }, $deaddrops),
    'drops' => array_map(static function (array $message): array {
        return [
            'id' => $message['public_id'],
            'channel' => $message['deaddrop_public_id'],
            'channelLabel' => $message['deaddrop_name'],
            'created' => $message['created_bucket'],
            'expires' => $message['expires_at'],
            'status' => $message['status'],
            'failedAttempts' => (int) $message['failed_attempts'],
        ];
    }, $messages),
];

require_once __DIR__ . '/../includes/header.php';
?>

<section class="output" id="output" aria-live="polite"></section>

<div class="suggestion-bar" id="suggestionBar"></div>

<form class="command-row" id="commandForm" autocomplete="off">
    <span class="prompt" id="prompt">node@deaddrop:~$</span>
    <input
        id="commandInput"
        class="cmd-input"
        type="text"
        spellcheck="false"
        autocomplete="off"
        autocapitalize="off"
        placeholder="type help"
    >
</form>

<template id="terminal-state"><?= json_encode($terminalState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></template>
<?= script_tag('assets/js/crypto.js') ?>
<?= script_tag('assets/js/keys.js') ?>
<?= script_tag('assets/js/terminal-dashboard.js') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
