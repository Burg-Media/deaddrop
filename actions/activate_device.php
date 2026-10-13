<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('activate.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

if (!device_trust_tables_exist($pdo)) {
    $_SESSION['device_activate_error'] = 'Trusted device database-migratie ontbreekt.';
    redirect('activate.php');
}

$lockedFor = throttle_locked_for($pdo, 'activate', client_ip());

if ($lockedFor > 0) {
    $_SESSION['device_activate_error'] = 'Te veel activatiepogingen. Probeer het over ' . throttle_wait_text($lockedFor) . ' opnieuw.';
    redirect('activate.php');
}

$activationCode = strtoupper(trim((string) ($_POST['activation_code'] ?? '')));

if (!preg_match('/^[A-Z0-9-]{8,64}$/', $activationCode)) {
    throttle_hit($pdo, 'activate', client_ip(), 10, 3600);
    $_SESSION['device_activate_error'] = 'Activatiecode is ongeldig.';
    redirect('activate.php');
}

$codeHash = activation_code_hash($activationCode);

$stmt = $pdo->prepare("
    SELECT
        id,
        user_id,
        label
    FROM device_activation_codes
    WHERE code_hash = ?
      AND used_at IS NULL
      AND expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$codeHash]);
$activation = $stmt->fetch();

if (!$activation) {
    throttle_hit($pdo, 'activate', client_ip(), 10, 3600);
    $_SESSION['device_activate_error'] = 'Activatiecode is ongeldig of verlopen.';
    redirect('activate.php');
}

$devicePublicId = generate_public_id();
$deviceSecret = bin2hex(random_bytes(32));

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO trusted_devices (
            public_id,
            user_id,
            label,
            device_secret_hash,
            status,
            activated_at,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            'trusted',
            NOW(),
            NOW()
        )
    ");
    $insert->execute([
        $devicePublicId,
        $activation['user_id'] !== null ? (int) $activation['user_id'] : null,
        $activation['label'],
        device_secret_hash($deviceSecret),
    ]);

    $update = $pdo->prepare('UPDATE device_activation_codes SET used_at = NOW() WHERE id = ? LIMIT 1');
    $update->execute([(int) $activation['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Device activation failed: ' . $e->getMessage());
    $_SESSION['device_activate_error'] = 'Device activeren is mislukt.';
    redirect('activate.php');
}

issue_trusted_device_cookie($devicePublicId, $deviceSecret);
$_SESSION['device_activate_notice'] = 'Device geactiveerd. Je kunt nu inloggen.';

redirect('login.php');
