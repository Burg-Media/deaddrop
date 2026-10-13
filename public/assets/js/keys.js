/*
 * Sleutelbeheer tussen browser en node: eigen sleutels openen (en zo nodig
 * upgraden), sleutels van contacten ophalen, en fingerprints pinnen.
 *
 * Pins staan op twee plekken: in de database (known_keys) én in localStorage.
 * Een operator die de tabel leegmaakt of een sleutel vervangt, komt niet langs
 * de lokale pin: de browser blokkeert dan het versturen tot je opnieuw controleert.
 */

function ddCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function ddGetJson(url) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.error || 'Verzoek mislukt.');
    }

    return data;
}

async function ddPostJson(url, data) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ...data, csrf_token: ddCsrfToken() })
    });
    const result = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(result.error || 'Verzoek mislukt.');
    }

    return result;
}

async function ddFetchMyKeys() {
    return ddGetJson('actions/my_keys.php');
}

/**
 * Opent de eigen sleutels met de private pass. Oude sleutels (250.000
 * iteraties, nog geen ondertekensleutel) worden meteen bijgewerkt.
 */
async function ddUnlockMyKeys(privatePass, onStatus = () => {}) {
    const me = await ddFetchMyKeys();
    onStatus('Private key lokaal openen met je private pass...');
    let opened = await openKeyRecord(me.record, privatePass);
    let upgraded = false;

    if (opened.needsUpgrade) {
        onStatus('Sleutels bijwerken naar de huidige standaard...');
        const result = await upgradeKeyRecord(opened, privatePass);
        await ddPostJson('actions/update_user_keys.php', { mode: 'upgrade', record: result.record });
        opened = result.opened;
        me.record = result.record;
        upgraded = true;
    }

    return { me, opened, upgraded };
}

async function ddFetchContact(deaddropRef, userRef) {
    const params = new URLSearchParams({ deaddrop_ref: deaddropRef, user_ref: userRef });
    return ddGetJson(`actions/fetch_public_key.php?${params.toString()}`);
}

function ddPinStorageKey(observerPublicId) {
    return `deaddrop:pins:${location.host}:${observerPublicId}`;
}

function ddLocalPins(observerPublicId) {
    try {
        return JSON.parse(localStorage.getItem(ddPinStorageKey(observerPublicId)) || '{}') || {};
    } catch (error) {
        return {};
    }
}

function ddSaveLocalPin(observerPublicId, subjectPublicId, fingerprint, verified) {
    try {
        const pins = ddLocalPins(observerPublicId);
        pins[subjectPublicId] = { fp: fingerprint, verified: Boolean(verified), at: new Date().toISOString().slice(0, 10) };
        localStorage.setItem(ddPinStorageKey(observerPublicId), JSON.stringify(pins));
    } catch (error) {
        // Geen localStorage (privévenster): de pin in de database blijft.
    }
}

/**
 * Beoordeelt de sleutel van een contact:
 *   new       nog nooit gezien (eerste contact)
 *   known     eerder gezien, niet buiten Deaddrop gecontroleerd
 *   verified  eerder gezien én gecontroleerd
 *   changed   anders dan eerder gezien: blokkeren tot opnieuw gecontroleerd
 */
async function ddAssessContact(observerPublicId, contact) {
    const fingerprint = await keyFingerprint(contact.public_key, contact.signing_public_key);
    const local = ddLocalPins(observerPublicId)[contact.user_public_id] || null;
    const server = contact.known || null;

    const localDiffers = local && local.fp !== fingerprint;
    const serverDiffers = server && server.fingerprint !== fingerprint;

    if (localDiffers || serverDiffers) {
        return {
            fingerprint,
            status: 'changed',
            previous: localDiffers ? local.fp : server.fingerprint
        };
    }

    if (!local && !server) {
        return { fingerprint, status: 'new', previous: null };
    }

    const verified = Boolean((local && local.verified) || (server && server.verified_at));

    return { fingerprint, status: verified ? 'verified' : 'known', previous: null };
}

async function ddPinContact(observerPublicId, subjectPublicId, fingerprint, verified) {
    const local = ddLocalPins(observerPublicId)[subjectPublicId];
    const keepVerified = Boolean(local && local.fp === fingerprint && local.verified);

    await ddPostJson('actions/pin_key.php', { subject_ref: subjectPublicId, fingerprint, verified: verified || keepVerified });
    ddSaveLocalPin(observerPublicId, subjectPublicId, fingerprint, verified || keepVerified);
}

function ddContactStatusText(assessment) {
    switch (assessment.status) {
        case 'verified':
            return 'Gecontroleerd: deze fingerprint heb je eerder buiten Deaddrop vergeleken.';
        case 'known':
            return 'Bekend, niet gecontroleerd. Vergelijk de fingerprint een keer persoonlijk of telefonisch.';
        case 'new':
            return 'Eerste contact. Vergelijk de fingerprint persoonlijk of telefonisch voordat je iets gevoeligs stuurt.';
        case 'changed':
            return 'De sleutel van dit contact is veranderd sinds je hem eerder zag. Dat kan een nieuw sleutelpaar zijn, of iemand die meeleest. Controleer de nieuwe fingerprint buiten Deaddrop voordat je verdergaat.';
        default:
            return '';
    }
}
