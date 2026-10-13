<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/keys.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

if (user_has_keys($pdo, (int) $currentUser['id'])) {
    redirect('dashboard.php');
}

$error = $_SESSION['key_setup_error'] ?? null;
unset($_SESSION['key_setup_error']);

$pageTitle = 'Private pass instellen';

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <h1>Private pass instellen</h1>

    <p>
        Je browser maakt nu twee sleutelparen: één om berichten voor jou te versleutelen,
        één om jouw berichten te ondertekenen. De private keys worden hier, op dit apparaat,
        ingepakt met je private pass. De pass zelf gaat nooit naar de server.
    </p>

    <?php if ($error): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="post" action="actions/store_user_keys.php" data-key-setup-form>
            <?= csrf_field() ?>

            <?php foreach (KEY_RECORD_COLUMNS as $column): ?>
                <input type="hidden" id="<?= e($column) ?>" name="<?= e($column) ?>">
            <?php endforeach; ?>

            <div class="form-group">
                <label for="private_pass">Private pass</label>
                <input
                    type="password"
                    id="private_pass"
                    autocomplete="new-password"
                    minlength="12"
                    required
                >
                <div class="strength" id="pass_strength" data-score="0"><span></span><small></small></div>
            </div>

            <div class="form-group">
                <label for="private_pass_repeat">Herhaal private pass</label>
                <input
                    type="password"
                    id="private_pass_repeat"
                    autocomplete="new-password"
                    minlength="12"
                    required
                >
            </div>

            <p>
                <small>
                    Kies iets lang: een zin van vier, vijf woorden is sterker dan een kort wachtwoord met tekens.
                    Zonder deze pass zijn je berichten niet te openen, ook niet door de beheerder van de node.
                    Dat is zo ontworpen.
                </small>
            </p>

            <div id="key_setup_status" class="notice is-hidden"></div>
            <div id="key_setup_error" class="notice error is-hidden"></div>

            <button type="submit">Sleutels lokaal genereren</button>
        </form>
    </div>
</section>

<?= script_tag('assets/js/crypto.js') ?>
<?= script_tag('assets/js/ui.js') ?>
<?= script_tag('assets/js/key-setup.js') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
