<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_guest();

$pageTitle = 'Inloggen';

$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-box">
    <h1>Inloggen</h1>
    <p>Log in om je Deaddrops en berichten te bekijken.</p>

    <?php if ($error): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (device_trust_is_required($pdo) && current_trusted_device($pdo) === null): ?>
        <div class="notice">
            Dit browserprofiel is nog niet trusted. Log eerst in; na correcte login sturen we je naar device activatie.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= APP_URL ?>/actions/login_action.php">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="username">Gebruikersnaam</label>
            <input
                type="text"
                id="username"
                name="username"
                autocomplete="username"
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Wachtwoord</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit">Inloggen</button>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
