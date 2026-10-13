<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/messages.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

$ref = (string) ($_GET['ref'] ?? '');

if ($ref === '') {
    http_response_code(404);
    exit('Bericht niet gevonden.');
}

/**
 * Alleen de bedoelde ontvanger komt hier, en alleen bij de eerste weergave
 * gaat de ciphertext mee naar de browser.
 */
$message = find_recipient_message($pdo, $ref, (int) $currentUser['id']);

if (!$message) {
    http_response_code(404);
    exit('Bericht niet gevonden of geen toegang.');
}

$firstView = $message['status'] === 'unopened' && claim_message_for_reading($pdo, (int) $message['id']);
$readable = $firstView && $message['encrypted_payload'] && $message['iv'] && $message['salt'];

$pageTitle = 'Bericht openen';

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/deaddrop.php?ref=<?= e($message['deaddrop_public_id']) ?>">&larr; Terug naar Deaddrop</a>
    </p>

    <h1>Bericht</h1>

    <p>
        Deaddrop:
        <strong><?= e($message['deaddrop_name']) ?></strong>
    </p>

    <?php if ($readable): ?>

        <div class="panel" data-decrypt-panel>
            <h2>Ontsleutelen</h2>

            <p>
                Voer je private pass in. Dit bericht is nu geopend: je hebt
                <?= READ_WINDOW_MINUTES ?> minuten en <?= MAX_DECRYPT_ATTEMPTS ?> pogingen.
                Ververs je deze pagina, dan is het bericht weg.
            </p>

            <form data-decrypt-message-form
                  data-message-ref="<?= e($message['public_id']) ?>"
                  data-deaddrop-ref="<?= e($message['deaddrop_public_id']) ?>">
                <input type="hidden" id="encrypted_payload" value="<?= e((string) $message['encrypted_payload']) ?>">
                <input type="hidden" id="iv" value="<?= e((string) $message['iv']) ?>">
                <input type="hidden" id="salt" value="<?= e((string) $message['salt']) ?>">

                <div class="form-group">
                    <label for="private_pass">Private pass</label>
                    <input
                        type="password"
                        id="private_pass"
                        autocomplete="off"
                        required
                    >
                </div>

                <button type="submit">Ontsleutel bericht</button>
            </form>

            <div id="decrypt_status" class="notice is-hidden"></div>
            <div id="decrypt_error" class="notice error is-hidden"></div>

            <div class="sender-check is-hidden" id="sender_check">
                <strong id="sender_title"></strong>
                <code class="fingerprint is-hidden" id="sender_fingerprint"></code>
                <p id="sender_text"></p>
            </div>

            <div class="message-box is-hidden" id="message_box">
                <pre id="decrypted_output"></pre>
            </div>

            <div class="notice is-hidden" id="burned_notice">
                Dit bericht is van de server gewist. Sluit je dit venster, dan bestaat het nergens meer.
            </div>
        </div>

        <?= script_tag('assets/js/crypto.js') ?>
        <?= script_tag('assets/js/ui.js') ?>
        <?= script_tag('assets/js/keys.js') ?>
        <?= script_tag('assets/js/open-message.js') ?>

    <?php elseif ($message['status'] === 'expired'): ?>

        <div class="panel">
            <h2>Bericht verlopen</h2>
            <p>Dit bericht is niet op tijd geopend en is gewist.</p>
        </div>

    <?php elseif ($message['status'] === 'locked'): ?>

        <div class="panel">
            <h2>Bericht vergrendeld</h2>
            <p>Na <?= MAX_DECRYPT_ATTEMPTS ?> verkeerde private passes is dit bericht gewist.</p>
        </div>

    <?php else: ?>

        <div class="panel">
            <h2>Al geopend</h2>
            <p>Dit bericht is al een keer geopend en daarna gewist. Het is niet opnieuw te lezen.</p>
        </div>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
