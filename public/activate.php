<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Device activeren';
$error = $_SESSION['device_activate_error'] ?? null;
$notice = $_SESSION['device_activate_notice'] ?? null;
unset($_SESSION['device_activate_error'], $_SESSION['device_activate_notice']);

if (device_trust_tables_exist($pdo) && current_trusted_device($pdo)) {
    redirect('login.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <h1>Device activeren</h1>

    <p>
        Dit browserprofiel is nog niet vertrouwd, of de bestaande device trust is verlopen/verwijderd.
        Vraag een admin om een nieuwe activatiecode als je deze niet hebt.
    </p>

    <?php if (!APP_DEVICE_TRUST_REQUIRED): ?>
        <div class="notice">
            Device gate staat nog niet verplicht aan. Je kunt deze pagina gebruiken om alvast devices te activeren.
        </div>
    <?php endif; ?>

    <?php if (!device_trust_tables_exist($pdo)): ?>
        <div class="notice error">
            Trusted device database-migratie ontbreekt.
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

    <div class="panel">
        <form method="post" action="actions/activate_device.php" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="activation_code">Activatiecode</label>
                <input
                    type="text"
                    id="activation_code"
                    name="activation_code"
                    autocomplete="one-time-code"
                    inputmode="text"
                    placeholder="ABCD1234-EF567890"
                    required
                >
            </div>

            <button type="submit">Device activeren</button>
        </form>
    </div>

    <div class="panel panel-subtle">
        <h2>Waarom zie ik dit?</h2>
        <p>
            Deaddrop accepteert alleen logins vanaf vertrouwde devices. Als cookies zijn gewist,
            het device is vervangen, of een admin het device heeft revoked, moet dit device opnieuw geactiveerd worden.
        </p>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
