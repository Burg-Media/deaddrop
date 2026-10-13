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
    http_response_code(404);
    exit('Deaddrop niet gevonden.');
}

/**
 * Check of huidige user lid is van deze Deaddrop.
 */
$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.public_id,
        d.name,
        d.default_expiry_minutes,
        d.max_expiry_minutes,
        dm.role AS member_role
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

/**
 * Mogelijke ontvangers ophalen: alle actieve leden behalve jezelf.
 */
$activeUserFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'AND u.deleted_at IS NULL' : '';

$membersStmt = $pdo->prepare("
    SELECT 
        u.public_id,
        u.username
    FROM deaddrop_members dm
    INNER JOIN users u ON u.id = dm.user_id
    INNER JOIN user_keys uk ON uk.user_id = u.id
    WHERE dm.deaddrop_id = ?
      AND dm.removed_at IS NULL
      AND u.id != ?
      {$activeUserFilter}
    ORDER BY u.username ASC
");
$membersStmt->execute([(int) $deaddrop['id'], (int) $currentUser['id']]);
$recipients = $membersStmt->fetchAll();

$error = $_SESSION['message_create_error'] ?? null;
unset($_SESSION['message_create_error']);

$pageTitle = 'Nieuw bericht';

require_once __DIR__ . '/../includes/header.php';
?>

<section>
    <p>
        <a href="<?= APP_URL ?>/deaddrop.php?ref=<?= e($deaddrop['public_id']) ?>">&larr; Terug naar Deaddrop</a>
    </p>

    <h1>Nieuw bericht</h1>

    <p>
        Deaddrop:
        <strong><?= e($deaddrop['name']) ?></strong>
    </p>

    <p>
        Je bericht wordt in deze browser versleuteld voor de ontvanger en ondertekend met jouw sleutel.
        De server krijgt alleen ciphertext en ziet niet van wie het komt.
    </p>

    <?php if ($error): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$recipients): ?>
        <div class="panel">
            <p>Er zijn geen andere leden met een actieve public key in deze Deaddrop.</p>
        </div>
    <?php else: ?>
        <form method="post" action="actions/store_message.php" data-encrypted-message-form data-user-ref="<?= e($currentUser['public_id']) ?>">
            <?= csrf_field() ?>

            <input type="hidden" name="deaddrop_ref" id="deaddrop_ref" value="<?= e($deaddrop['public_id']) ?>">
            <input type="hidden" id="encrypted_payload" name="encrypted_payload">
            <input type="hidden" id="iv" name="iv">
            <input type="hidden" id="salt" name="salt">

            <div class="form-group">
                <label for="recipient_ref">Ontvanger</label>
                <select id="recipient_ref" name="recipient_ref" required>
                    <option value="">Kies ontvanger</option>
                    <?php foreach ($recipients as $recipient): ?>
                        <option value="<?= e($recipient['public_id']) ?>">
                            <?= e($recipient['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="key-check is-hidden" id="recipient_key">
                <strong id="recipient_key_title"></strong>
                <code class="fingerprint" id="recipient_fingerprint"></code>
                <p id="recipient_key_text"></p>
                <p class="fingerprint-previous is-hidden" id="recipient_key_previous"></p>
                <button type="button" class="button-secondary is-hidden" id="recipient_key_verify">Ik heb deze fingerprint buiten Deaddrop gecontroleerd</button>
            </div>

            <div class="form-group">
                <label for="message">Bericht</label>
                <textarea
                    id="message"
                    rows="8"
                    maxlength="10000"
                    required
                ></textarea>
            </div>

            <div class="form-group">
                <label for="expiry_minutes">Verloopt na</label>
                <select id="expiry_minutes" name="expiry_minutes" required>
                    <?php foreach ([15 => '15 minuten', 60 => '1 uur', 1440 => '24 uur', 10080 => '7 dagen'] as $minutes => $label): ?>
                        <?php if ($minutes <= (int) $deaddrop['max_expiry_minutes']): ?>
                            <option value="<?= $minutes ?>" <?= (int) $deaddrop['default_expiry_minutes'] === $minutes ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="sign_group">
                <label for="sender_pass">Jouw private pass (om te ondertekenen)</label>
                <input type="password" id="sender_pass" autocomplete="off">
            </div>

            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" id="send_anonymous">
                    Anoniem versturen: niet ondertekenen
                </label>
                <small class="muted">De ontvanger ziet dan niet van wie het bericht komt, en kan ook niet controleren dat het echt van een lid is.</small>
            </div>

            <div id="message_encrypt_status" class="notice is-hidden"></div>
            <div id="message_encrypt_error" class="notice error is-hidden"></div>

            <button type="submit">Versleutelen en klaarzetten</button>
        </form>

        <?= script_tag('assets/js/crypto.js') ?>
        <?= script_tag('assets/js/ui.js') ?>
        <?= script_tag('assets/js/keys.js') ?>
        <?= script_tag('assets/js/create-message.js') ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
