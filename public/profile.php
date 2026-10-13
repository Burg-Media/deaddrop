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

$record = load_key_record($pdo, (int) $currentUser['id']);

$contacts = [];

if (db_table_exists($pdo, 'known_keys')) {
    $stmt = $pdo->prepare("
        SELECT u.username, kk.fingerprint, kk.first_seen_at, kk.verified_at
        FROM known_keys kk
        INNER JOIN users u ON u.id = kk.subject_user_id
        WHERE kk.observer_user_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([(int) $currentUser['id']]);
    $contacts = $stmt->fetchAll();
}

$isNew = isset($_GET['nieuw']);
$passwordNotice = $_SESSION['password_notice'] ?? null;
$passwordError = $_SESSION['password_error'] ?? null;
unset($_SESSION['password_notice'], $_SESSION['password_error']);
$pageTitle = 'Sleutels';

require_once __DIR__ . '/../includes/header.php';
?>

<section data-profile data-user-ref="<?= e($currentUser['public_id']) ?>" data-username="<?= e($currentUser['username']) ?>">
    <h1>Sleutels</h1>

    <?php if ($isNew): ?>
        <div class="notice">
            Je sleutels zijn aangemaakt. Hieronder staat je fingerprint: lees die een keer voor aan wie je berichten stuurt,
            dan weet je allebei dat de node er niemand tussen heeft gezet.
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Jouw fingerprint</h2>
        <p>SHA-256 over je twee public keys, uitgerekend in deze browser. Vergelijk hem persoonlijk of telefonisch, nooit via Deaddrop zelf.</p>
        <code class="fingerprint fingerprint-large" id="own_fingerprint">wordt berekend...</code>
        <p>
            <small>
                Sleutelafleiding: <?= e((string) ($record['kdf_algo'] ?? '')) ?>, <?= number_format((int) ($record['kdf_iterations'] ?? 0), 0, ',', '.') ?> iteraties.
                Ondertekensleutel: <?= !empty($record['signing_public_key']) ? 'ja' : 'nog niet (komt erbij bij je volgende ontsleuteling)' ?>.
            </small>
        </p>
    </div>

    <div id="profile_status" class="notice is-hidden"></div>
    <div id="profile_error" class="notice error is-hidden"></div>

    <div class="panel">
        <h2>Private pass wijzigen</h2>
        <p>Je sleutelparen blijven gelijk, alleen de verpakking verandert. Je fingerprint en je contacten merken er niets van.</p>
        <form id="rewrap_form">
            <div class="form-group">
                <label for="rewrap_old">Huidige private pass</label>
                <input type="password" id="rewrap_old" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="rewrap_new">Nieuwe private pass</label>
                <input type="password" id="rewrap_new" autocomplete="new-password" minlength="12" required>
                <div class="strength" id="rewrap_strength" data-score="0"><span></span><small></small></div>
            </div>
            <div class="form-group">
                <label for="rewrap_repeat">Herhaal nieuwe private pass</label>
                <input type="password" id="rewrap_repeat" autocomplete="new-password" minlength="12" required>
            </div>
            <button type="submit">Pass wijzigen</button>
        </form>
    </div>

    <div class="panel">
        <h2>Back-up</h2>
        <p>
            Een bestand met je ingepakte sleutels. Zonder je private pass is het onbruikbaar, ook voor wie het vindt.
            Bewaar het offline. Herstel via de node is onmogelijk, dat is zo ontworpen: dit bestand is je enige reservesleutel.
        </p>
        <div class="actions-row">
            <button type="button" id="backup_download">Back-up downloaden</button>
        </div>
        <form id="restore_form">
            <div class="form-group">
                <label for="restore_file">Back-up terugzetten</label>
                <input type="file" id="restore_file" accept="application/json,.json" required>
            </div>
            <div class="form-group">
                <label for="restore_pass">Private pass van die back-up</label>
                <input type="password" id="restore_pass" autocomplete="off" required>
            </div>
            <button type="submit">Back-up terugzetten</button>
        </form>
    </div>

    <div class="panel">
        <h2>Nieuw sleutelpaar</h2>
        <p>
            Voor als je denkt dat je private pass of een apparaat niet meer te vertrouwen is. Je fingerprint verandert,
            je contacten krijgen een waarschuwing, en berichten die nog voor je oude sleutel klaarstaan zijn daarna niet meer te openen.
        </p>
        <form id="rotate_form">
            <div class="form-group">
                <label for="rotate_pass">Private pass voor de nieuwe sleutels</label>
                <input type="password" id="rotate_pass" autocomplete="new-password" minlength="12" required>
            </div>
            <div class="form-group">
                <label for="rotate_repeat">Herhaal</label>
                <input type="password" id="rotate_repeat" autocomplete="new-password" minlength="12" required>
            </div>
            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" id="rotate_confirm" required>
                    Ik begrijp dat openstaande berichten voor mijn oude sleutel verloren gaan
                </label>
            </div>
            <button type="submit">Nieuw sleutelpaar maken</button>
        </form>
    </div>

    <div class="panel" id="wachtwoord">
        <h2>Accountwachtwoord</h2>
        <p>Om in te loggen. Staat los van je private pass: dit wachtwoord kent de server (als hash), je private pass nooit.</p>

        <?php if ($passwordNotice): ?>
            <div class="notice"><?= e($passwordNotice) ?></div>
        <?php endif; ?>

        <?php if ($passwordError): ?>
            <div class="notice error"><?= e($passwordError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= APP_URL ?>/actions/change_password.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="current_password">Huidig wachtwoord</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="new_password">Nieuw wachtwoord</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="12" required>
            </div>
            <div class="form-group">
                <label for="new_password_repeat">Herhaal nieuw wachtwoord</label>
                <input type="password" id="new_password_repeat" name="new_password_repeat" autocomplete="new-password" minlength="12" required>
            </div>
            <button type="submit">Wachtwoord wijzigen</button>
        </form>
    </div>

    <div class="panel">
        <h2>Contacten</h2>
        <?php if (!$contacts): ?>
            <p>Nog geen contacten. Zodra je iemand een bericht stuurt of van iemand ontvangt, onthoudt Deaddrop diens fingerprint.</p>
        <?php else: ?>
            <div class="identity-list">
                <?php foreach ($contacts as $contact): ?>
                    <div class="identity-card">
                        <strong><?= e($contact['username']) ?></strong>
                        <code class="fingerprint"><?= e(implode("\n", array_map('trim', str_split(trim(chunk_split(strtoupper($contact['fingerprint']), 4, ' ')), 40)))) ?></code>
                        <small>
                            <?= $contact['verified_at'] ? 'Gecontroleerd op ' . e(date('d-m-Y', strtotime((string) $contact['verified_at']))) : 'Niet gecontroleerd' ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= script_tag('assets/js/crypto.js') ?>
<?= script_tag('assets/js/ui.js') ?>
<?= script_tag('assets/js/keys.js') ?>
<?= script_tag('assets/js/profile.js') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
