document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-decrypt-message-form]');

    if (!form) {
        return;
    }

    const messageRef = form.dataset.messageRef;
    const deaddropRef = form.dataset.deaddropRef;
    const privatePassInput = document.getElementById('private_pass');
    const submitButton = form.querySelector('button[type="submit"]');
    const statusBox = document.getElementById('decrypt_status');
    const errorBox = document.getElementById('decrypt_error');
    const messageBox = document.getElementById('message_box');
    const output = document.getElementById('decrypted_output');
    const burned = document.getElementById('burned_notice');

    const senderBox = document.getElementById('sender_check');
    const senderTitle = document.getElementById('sender_title');
    const senderFingerprint = document.getElementById('sender_fingerprint');
    const senderText = document.getElementById('sender_text');

    const sealed = {
        encrypted_payload: document.getElementById('encrypted_payload').value,
        iv: document.getElementById('iv').value,
        salt: document.getElementById('salt').value
    };

    function fail(message) {
        ddHide(statusBox);
        ddShow(errorBox, message);
    }

    function showSender(status, title, text, fingerprint = null) {
        senderBox.dataset.status = status;
        senderTitle.textContent = title;
        senderText.textContent = text;

        if (fingerprint) {
            ddShow(senderFingerprint, formatFingerprint(fingerprint));
        }

        ddShow(senderBox);
    }

    async function checkSender(opened, me) {
        if (opened.format === 'legacy') {
            showSender('unsigned', 'Niet ondertekend', 'Dit bericht is gemaakt met een oudere versie, zonder envelop en handtekening.');
            return;
        }

        if (opened.envelope.to !== me) {
            showSender('changed', 'Niet aan jou gericht', 'De envelop noemt een andere ontvanger. Dit bericht is doorgestuurd of gemanipuleerd.');
            return;
        }

        if (!opened.signature || !opened.envelope.from) {
            showSender('unsigned', 'Anoniem bericht', 'De afzender heeft niet ondertekend. Je kunt niet controleren van wie dit bericht komt.');
            return;
        }

        let contact;

        try {
            contact = await ddFetchContact(deaddropRef, opened.envelope.from);
        } catch (error) {
            showSender('changed', 'Afzender onbekend', 'Het bericht is ondertekend, maar de sleutel van de afzender is niet op te halen. De afzender is niet te controleren.');
            return;
        }

        const valid = await verifyEnvelopeSignature(opened.inner, opened.signature, contact.signing_public_key);

        if (!valid) {
            showSender('changed', 'Handtekening ongeldig', `Het bericht zegt van ${contact.username} te komen, maar de handtekening klopt niet. Vertrouw de inhoud niet.`);
            return;
        }

        const assessment = await ddAssessContact(me, contact);

        if (assessment.status === 'changed') {
            showSender('changed', `Ondertekend door ${contact.username}, met een nieuwe sleutel`, ddContactStatusText(assessment), assessment.fingerprint);
            return;
        }

        if (assessment.status === 'new') {
            await ddPinContact(me, contact.user_public_id, assessment.fingerprint, false).catch(() => {});
        }

        const sent = opened.envelope.ts ? new Date(opened.envelope.ts * 1000).toLocaleString('nl-NL', { dateStyle: 'medium', timeStyle: 'short' }) : '';
        showSender(
            assessment.status === 'verified' ? 'verified' : 'signed',
            `Ondertekend door ${contact.username}`,
            `${assessment.status === 'verified' ? 'Handtekening klopt en de sleutel is door jou gecontroleerd.' : 'Handtekening klopt. ' + ddContactStatusText(assessment)}${sent ? ' Verstuurd: ' + sent + '.' : ''}`,
            assessment.fingerprint
        );
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        ddHide(errorBox);

        const privatePass = privatePassInput.value;

        if (!privatePass) {
            fail('Vul je private pass in.');
            return;
        }

        if (!window.crypto?.subtle) {
            fail('Web Crypto API is niet beschikbaar. Open deze node via HTTPS.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Ontsleutelen...';

        let unlocked;

        try {
            unlocked = await ddUnlockMyKeys(privatePass, message => ddShow(statusBox, message));
        } catch (error) {
            privatePassInput.value = '';
            submitButton.disabled = false;
            submitButton.textContent = 'Ontsleutel bericht';

            if (!(error instanceof DeaddropWrongPassError)) {
                console.error(error);
                fail(error.message || 'Sleutels openen is mislukt.');
                return;
            }

            try {
                const result = await ddPostJson('actions/failed_attempt.php', { message_ref: messageRef });

                if (result.status === 'locked') {
                    ddHide(form);
                    fail('Private pass onjuist. Dit was de laatste poging: het bericht is gewist.');
                } else if (result.status !== 'reading') {
                    ddHide(form);
                    fail('Het leesvenster is voorbij: het bericht is gewist.');
                } else {
                    fail(`Private pass onjuist. Nog ${result.remaining} ${result.remaining === 1 ? 'poging' : 'pogingen'}.`);
                }
            } catch (postError) {
                fail('Private pass onjuist.');
            }

            return;
        }

        privatePassInput.value = '';

        try {
            ddShow(statusBox, 'Bericht lokaal ontsleutelen...');
            const opened = await unsealMessage(sealed, unlocked.opened.decryptKey);

            output.textContent = opened.envelope.body ?? '';
            ddHide(form);
            ddHide(statusBox);
            ddShow(messageBox);

            await ddPostJson('actions/mark_opened.php', { message_ref: messageRef }).catch(() => {});
            ddShow(burned);

            await checkSender(opened, unlocked.me.user_public_id);
        } catch (error) {
            console.error(error);
            fail('Dit bericht is niet te ontsleutelen met jouw sleutel.');
        }
    });
});
