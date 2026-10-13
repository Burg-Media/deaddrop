document.addEventListener('DOMContentLoaded', async () => {
    const root = document.querySelector('[data-profile]');

    if (!root) {
        return;
    }

    const statusBox = document.getElementById('profile_status');
    const errorBox = document.getElementById('profile_error');
    const fingerprintNode = document.getElementById('own_fingerprint');

    let me = null;

    function status(message) {
        ddHide(errorBox);
        ddShow(statusBox, message);
    }

    function fail(error) {
        ddHide(statusBox);
        ddShow(errorBox, error instanceof DeaddropWrongPassError ? 'Die private pass klopt niet.' : (error.message || 'Er ging iets mis.'));
    }

    async function refresh() {
        me = await ddFetchMyKeys();
        fingerprintNode.textContent = formatFingerprint(await keyFingerprint(me.record.public_key, me.record.signing_public_key));
    }

    async function withButton(form, label, task) {
        const button = form.querySelector('button[type="submit"]');
        const original = button.textContent;
        button.disabled = true;
        button.textContent = label;

        try {
            await task();
        } catch (error) {
            console.error(error);
            fail(error);
        } finally {
            button.disabled = false;
            button.textContent = original;
        }
    }

    function checkNewPass(pass, repeat) {
        if (pass.length < 12) {
            throw new Error('Gebruik minimaal 12 tekens.');
        }

        if (pass !== repeat) {
            throw new Error('De twee private passes zijn niet gelijk.');
        }
    }

    try {
        await refresh();
    } catch (error) {
        fail(error);
    }

    ddBindStrengthMeter(document.getElementById('rewrap_new'), document.getElementById('rewrap_strength'));

    const rewrapForm = document.getElementById('rewrap_form');
    rewrapForm.addEventListener('submit', event => {
        event.preventDefault();

        withButton(rewrapForm, 'Bezig...', async () => {
            const oldPass = document.getElementById('rewrap_old');
            const newPass = document.getElementById('rewrap_new');
            const repeat = document.getElementById('rewrap_repeat');
            checkNewPass(newPass.value, repeat.value);

            status('Sleutels openen met je huidige pass...');
            const { opened } = await ddUnlockMyKeys(oldPass.value);
            status('Opnieuw inpakken met je nieuwe pass...');
            const record = await packKeyRecord(newPass.value, {
                encryptionPublicKey: opened.encryptionPublicKey,
                encryptionPkcs8: opened.encryptionPkcs8,
                signingPublicKey: opened.signingPublicKey,
                signingPkcs8: opened.signingPkcs8
            });

            await ddPostJson('actions/update_user_keys.php', { mode: 'rewrap', record });
            rewrapForm.reset();
            await refresh();
            status('Je private pass is gewijzigd. Je fingerprint is gelijk gebleven.');
        });
    });

    document.getElementById('backup_download').addEventListener('click', () => {
        if (!me) {
            return;
        }

        const backup = {
            format: 'deaddrop-key-backup',
            version: 1,
            node: location.host,
            username: me.username,
            user_public_id: me.user_public_id,
            created: new Date().toISOString().slice(0, 10),
            record: me.record
        };
        const blob = new Blob([JSON.stringify(backup, null, 2)], { type: 'application/json' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `deaddrop-sleutels-${me.username}-${backup.created}.json`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        status('Back-up gedownload. Zet hem op een plek buiten deze computer.');
    });

    const restoreForm = document.getElementById('restore_form');
    restoreForm.addEventListener('submit', event => {
        event.preventDefault();

        withButton(restoreForm, 'Bezig...', async () => {
            const file = document.getElementById('restore_file').files[0];
            const pass = document.getElementById('restore_pass');
            const backup = JSON.parse(await file.text());

            if (backup.format !== 'deaddrop-key-backup' || !backup.record) {
                throw new Error('Dit is geen Deaddrop-sleutelback-up.');
            }

            status('Back-up openen met de private pass...');
            let opened = await openKeyRecord(backup.record, pass.value);
            const upgraded = await upgradeKeyRecord(opened, pass.value);

            await ddPostJson('actions/update_user_keys.php', { mode: 'replace', record: upgraded.record });
            restoreForm.reset();
            await refresh();
            status('Back-up teruggezet.');
        });
    });

    const rotateForm = document.getElementById('rotate_form');
    rotateForm.addEventListener('submit', event => {
        event.preventDefault();

        withButton(rotateForm, 'Bezig...', async () => {
            const pass = document.getElementById('rotate_pass');
            const repeat = document.getElementById('rotate_repeat');
            checkNewPass(pass.value, repeat.value);

            status('Nieuwe sleutelparen worden lokaal gemaakt...');
            const record = await createKeyRecord(pass.value);
            await ddPostJson('actions/update_user_keys.php', { mode: 'replace', record });
            rotateForm.reset();
            await refresh();
            status('Nieuw sleutelpaar actief. Lees je nieuwe fingerprint voor aan je contacten.');
        });
    });
});
