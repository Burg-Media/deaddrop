document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-key-setup-form]');

    if (!form) {
        return;
    }

    const privatePass = document.getElementById('private_pass');
    const privatePassRepeat = document.getElementById('private_pass_repeat');
    const submitButton = form.querySelector('button[type="submit"]');
    const statusNode = document.getElementById('key_setup_status');
    const errorNode = document.getElementById('key_setup_error');

    ddBindStrengthMeter(privatePass, document.getElementById('pass_strength'));

    function fail(message) {
        ddHide(statusNode);
        ddShow(errorNode, message);
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        ddHide(errorNode);

        if (!window.crypto?.subtle) {
            fail('Web Crypto API is niet beschikbaar. Open deze node via HTTPS.');
            return;
        }

        if (privatePass.value.length < 12) {
            fail('Gebruik minimaal 12 tekens voor je private pass.');
            return;
        }

        if (privatePass.value !== privatePassRepeat.value) {
            fail('De twee private passes zijn niet gelijk.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Bezig...';

        try {
            ddShow(statusNode, 'Sleutelparen worden lokaal gegenereerd en ingepakt. Dit duurt een paar seconden.');
            const record = await createKeyRecord(privatePass.value);

            Object.entries(record).forEach(([name, value]) => {
                const field = document.getElementById(name);

                if (field) {
                    field.value = String(value);
                }
            });

            privatePass.value = '';
            privatePassRepeat.value = '';
            ddShow(statusNode, 'Ingepakte sleutels worden opgeslagen...');
            form.submit();
        } catch (error) {
            console.error(error);
            fail('Sleutels genereren of inpakken is mislukt.');
            submitButton.disabled = false;
            submitButton.textContent = 'Sleutels lokaal genereren';
        }
    });
});
