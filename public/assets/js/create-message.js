document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-encrypted-message-form]');

    if (!form) {
        return;
    }

    const me = form.dataset.userRef;
    const deaddropRef = document.getElementById('deaddrop_ref').value;
    const recipientInput = document.getElementById('recipient_ref');
    const messageInput = document.getElementById('message');
    const senderPass = document.getElementById('sender_pass');
    const anonymous = document.getElementById('send_anonymous');
    const signGroup = document.getElementById('sign_group');
    const submitButton = form.querySelector('button[type="submit"]');
    const statusBox = document.getElementById('message_encrypt_status');
    const errorBox = document.getElementById('message_encrypt_error');

    const keyBox = document.getElementById('recipient_key');
    const keyTitle = document.getElementById('recipient_key_title');
    const keyFingerprint = document.getElementById('recipient_fingerprint');
    const keyText = document.getElementById('recipient_key_text');
    const keyPrevious = document.getElementById('recipient_key_previous');
    const keyVerify = document.getElementById('recipient_key_verify');

    let contact = null;
    let assessment = null;

    function fail(message) {
        ddHide(statusBox);
        ddShow(errorBox, message);
    }

    function renderKey() {
        if (!contact || !assessment) {
            ddHide(keyBox);
            return;
        }

        keyBox.dataset.status = assessment.status;
        keyTitle.textContent = `Fingerprint van ${contact.username}`;
        keyFingerprint.textContent = formatFingerprint(assessment.fingerprint);
        keyText.textContent = ddContactStatusText(assessment);

        if (assessment.status === 'changed' && assessment.previous) {
            ddShow(keyPrevious, `Eerder gezien: ${formatFingerprint(assessment.previous)}`);
        } else {
            ddHide(keyPrevious);
        }

        if (assessment.status === 'verified') {
            ddHide(keyVerify);
        } else {
            ddShow(keyVerify);
        }

        ddShow(keyBox);
    }

    async function loadRecipient() {
        contact = null;
        assessment = null;
        ddHide(errorBox);

        if (!recipientInput.value) {
            renderKey();
            return;
        }

        try {
            contact = await ddFetchContact(deaddropRef, recipientInput.value);
            assessment = await ddAssessContact(me, contact);
        } catch (error) {
            fail(error.message || 'Public key van de ontvanger ophalen is mislukt.');
        }

        renderKey();
    }

    recipientInput.addEventListener('change', loadRecipient);

    keyVerify.addEventListener('click', async () => {
        if (!contact || !assessment) {
            return;
        }

        try {
            await ddPinContact(me, contact.user_public_id, assessment.fingerprint, true);
            assessment = { ...assessment, status: 'verified', previous: null };
            renderKey();
        } catch (error) {
            fail(error.message || 'Opslaan van de controle is mislukt.');
        }
    });

    anonymous.addEventListener('change', () => {
        signGroup.classList.toggle('is-muted', anonymous.checked);
        senderPass.disabled = anonymous.checked;
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        ddHide(errorBox);

        const body = messageInput.value.trim();

        if (!body || !recipientInput.value) {
            fail('Kies een ontvanger en vul een bericht in.');
            return;
        }

        if (!window.crypto?.subtle) {
            fail('Web Crypto API is niet beschikbaar. Open deze node via HTTPS.');
            return;
        }

        if (!contact || !assessment) {
            await loadRecipient();

            if (!contact) {
                return;
            }
        }

        if (assessment.status === 'changed') {
            fail('De sleutel van deze ontvanger is veranderd. Controleer eerst de nieuwe fingerprint.');
            return;
        }

        if (!anonymous.checked && !senderPass.value) {
            fail('Vul je private pass in om te ondertekenen, of kies anoniem versturen.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Versleutelen...';

        try {
            let sender = null;

            if (!anonymous.checked) {
                const unlocked = await ddUnlockMyKeys(senderPass.value, message => ddShow(statusBox, message));
                sender = { publicId: me, signKey: unlocked.opened.signKey };
            }

            senderPass.value = '';

            if (assessment.status === 'new') {
                await ddPinContact(me, contact.user_public_id, assessment.fingerprint, false);
            }

            ddShow(statusBox, 'Bericht lokaal versleutelen...');
            const sealed = await sealMessage(body, { publicId: contact.user_public_id, publicKey: contact.public_key }, sender);

            document.getElementById('encrypted_payload').value = sealed.encrypted_payload;
            document.getElementById('iv').value = sealed.iv;
            document.getElementById('salt').value = sealed.salt;

            messageInput.value = '';
            ddShow(statusBox, 'Versleuteld bericht wordt opgeslagen...');
            form.submit();
        } catch (error) {
            console.error(error);
            fail(error instanceof DeaddropWrongPassError ? 'Je private pass klopt niet.' : (error.message || 'Versleutelen is mislukt.'));
            submitButton.disabled = false;
            submitButton.textContent = 'Versleutelen en klaarzetten';
        }
    });
});
