/*
 * Kleine UI-helpers voor de gewone pagina's. Tonen en verbergen gaat via de
 * klasse is-hidden: de CSP staat geen inline styles toe.
 */

function ddShow(node, text = null) {
    if (!node) {
        return;
    }

    if (text !== null) {
        node.textContent = text;
    }

    node.classList.remove('is-hidden');
}

function ddHide(node) {
    if (!node) {
        return;
    }

    node.classList.add('is-hidden');
}

function ddBindStrengthMeter(input, meter) {
    if (!input || !meter) {
        return;
    }

    const label = meter.querySelector('small');

    input.addEventListener('input', () => {
        const strength = estimatePassStrength(input.value);
        meter.dataset.score = String(strength.score);

        if (label) {
            label.textContent = strength.label;
        }
    });
}
