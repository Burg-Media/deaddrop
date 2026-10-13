/*
 * Deaddrop crypto. Alles wat met sleutels en berichtinhoud te maken heeft,
 * gebeurt hier, in de browser, met de Web Crypto API. De server krijgt alleen
 * public keys, ingepakte private keys en ciphertext te zien.
 *
 * Per gebruiker twee sleutelparen:
 *   RSA-OAEP 4096 (SHA-256)  om berichten voor die gebruiker te versleutelen
 *   ECDSA P-256 (SHA-256)    om verstuurde berichten te ondertekenen
 * Beide private keys worden ingepakt met AES-GCM, met een sleutel die uit de
 * private pass wordt afgeleid (PBKDF2-SHA256, aantal iteraties staat in het record).
 *
 * Per bericht een eigen AES-256-GCM-sleutel, versleuteld voor de ontvanger.
 * De handtekening van de afzender zit ín de versleutelde envelop: de server
 * ziet niet van wie een bericht komt, de ontvanger kan het wel controleren.
 */

const DD_KDF_ALGO = 'PBKDF2-SHA256';
const DD_KDF_ITERATIONS = 600000;
const DD_KDF_LEGACY_ITERATIONS = 250000;
const DD_ENVELOPE_VERSION = 1;

const DD_RSA = { name: 'RSA-OAEP', hash: 'SHA-256' };
const DD_ECDSA = { name: 'ECDSA', namedCurve: 'P-256' };
const DD_ECDSA_SIGN = { name: 'ECDSA', hash: 'SHA-256' };

function bytesToBase64(bytes) {
    const buffer = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    let binary = '';

    for (let i = 0; i < buffer.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, buffer.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

function base64ToBytes(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

function generateRandomBytes(length) {
    const bytes = new Uint8Array(length);
    crypto.getRandomValues(bytes);
    return bytes;
}

function utf8(text) {
    return new TextEncoder().encode(text);
}

/* Sleutelafleiding en inpakken ------------------------------------------- */

async function deriveWrappingKey(privatePass, saltBytes, iterations) {
    const baseKey = await crypto.subtle.importKey('raw', utf8(privatePass), { name: 'PBKDF2' }, false, ['deriveKey']);

    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt: saltBytes, iterations, hash: 'SHA-256' },
        baseKey,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    );
}

async function wrapBytes(wrappingKey, bytes) {
    const iv = generateRandomBytes(12);
    const data = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, wrappingKey, bytes);

    return { data: bytesToBase64(data), iv: bytesToBase64(iv) };
}

async function unwrapBytes(wrappingKey, dataBase64, ivBase64) {
    return crypto.subtle.decrypt({ name: 'AES-GCM', iv: base64ToBytes(ivBase64) }, wrappingKey, base64ToBytes(dataBase64));
}

/* Sleutelrecords ---------------------------------------------------------- */

async function generateEncryptionKeyPair() {
    const pair = await crypto.subtle.generateKey(
        { ...DD_RSA, modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]) },
        true,
        ['encrypt', 'decrypt']
    );

    return {
        publicKey: bytesToBase64(await crypto.subtle.exportKey('spki', pair.publicKey)),
        pkcs8: await crypto.subtle.exportKey('pkcs8', pair.privateKey)
    };
}

async function generateSigningKeyPair() {
    const pair = await crypto.subtle.generateKey(DD_ECDSA, true, ['sign', 'verify']);

    return {
        publicKey: bytesToBase64(await crypto.subtle.exportKey('spki', pair.publicKey)),
        pkcs8: await crypto.subtle.exportKey('pkcs8', pair.privateKey)
    };
}

/**
 * Pakt beide private keys in met een sleutel uit de private pass.
 * Altijd een nieuwe salt en nieuwe IV's, altijd het huidige aantal iteraties.
 */
async function packKeyRecord(privatePass, parts) {
    const salt = generateRandomBytes(16);
    const wrappingKey = await deriveWrappingKey(privatePass, salt, DD_KDF_ITERATIONS);
    const encryption = await wrapBytes(wrappingKey, parts.encryptionPkcs8);
    const signing = await wrapBytes(wrappingKey, parts.signingPkcs8);

    return {
        public_key: parts.encryptionPublicKey,
        encrypted_private_key: encryption.data,
        private_key_iv: encryption.iv,
        private_key_salt: bytesToBase64(salt),
        signing_public_key: parts.signingPublicKey,
        encrypted_signing_private_key: signing.data,
        signing_private_key_iv: signing.iv,
        kdf_algo: DD_KDF_ALGO,
        kdf_iterations: DD_KDF_ITERATIONS
    };
}

async function createKeyRecord(privatePass) {
    const [encryption, signing] = await Promise.all([generateEncryptionKeyPair(), generateSigningKeyPair()]);

    return packKeyRecord(privatePass, {
        encryptionPublicKey: encryption.publicKey,
        encryptionPkcs8: encryption.pkcs8,
        signingPublicKey: signing.publicKey,
        signingPkcs8: signing.pkcs8
    });
}

/**
 * Opent een sleutelrecord met de private pass. Gooit DeaddropWrongPassError
 * als de pass niet klopt (AES-GCM weigert dan het uitpakken).
 */
class DeaddropWrongPassError extends Error {}

async function openKeyRecord(record, privatePass) {
    const iterations = Number(record.kdf_iterations) || DD_KDF_LEGACY_ITERATIONS;
    const wrappingKey = await deriveWrappingKey(privatePass, base64ToBytes(record.private_key_salt), iterations);

    let encryptionPkcs8;
    let signingPkcs8 = null;

    try {
        encryptionPkcs8 = await unwrapBytes(wrappingKey, record.encrypted_private_key, record.private_key_iv);

        if (record.encrypted_signing_private_key) {
            signingPkcs8 = await unwrapBytes(wrappingKey, record.encrypted_signing_private_key, record.signing_private_key_iv);
        }
    } catch (error) {
        throw new DeaddropWrongPassError('Private pass klopt niet.');
    }

    const decryptKey = await crypto.subtle.importKey('pkcs8', encryptionPkcs8, DD_RSA, false, ['decrypt']);
    const signKey = signingPkcs8
        ? await crypto.subtle.importKey('pkcs8', signingPkcs8, DD_ECDSA, false, ['sign'])
        : null;

    return {
        encryptionPublicKey: record.public_key,
        encryptionPkcs8,
        signingPublicKey: record.signing_public_key || null,
        signingPkcs8,
        decryptKey,
        signKey,
        needsUpgrade: iterations < DD_KDF_ITERATIONS || !signingPkcs8 || record.kdf_algo !== DD_KDF_ALGO
    };
}

/**
 * Brengt een oud record naar de huidige standaard: 600.000 iteraties en een
 * ondertekensleutel. Het versleutelsleutelpaar blijft gelijk, dus berichten
 * die al klaarstaan blijven leesbaar.
 */
async function upgradeKeyRecord(opened, privatePass) {
    let signingPublicKey = opened.signingPublicKey;
    let signingPkcs8 = opened.signingPkcs8;

    if (!signingPkcs8) {
        const signing = await generateSigningKeyPair();
        signingPublicKey = signing.publicKey;
        signingPkcs8 = signing.pkcs8;
    }

    const record = await packKeyRecord(privatePass, {
        encryptionPublicKey: opened.encryptionPublicKey,
        encryptionPkcs8: opened.encryptionPkcs8,
        signingPublicKey,
        signingPkcs8
    });

    return {
        record,
        opened: {
            ...opened,
            signingPublicKey,
            signingPkcs8,
            signKey: await crypto.subtle.importKey('pkcs8', signingPkcs8, DD_ECDSA, false, ['sign']),
            needsUpgrade: false
        }
    };
}

/* Fingerprints ------------------------------------------------------------ */

/**
 * SHA-256 over beide public keys (SPKI). Verandert er één sleutel, dan
 * verandert de fingerprint. Twee mensen die dezelfde reeks voorlezen,
 * weten dat de server niemand ertussen heeft gezet.
 */
async function keyFingerprint(encryptionPublicKey, signingPublicKey) {
    const encryption = base64ToBytes(encryptionPublicKey);
    const signing = signingPublicKey ? base64ToBytes(signingPublicKey) : new Uint8Array(0);
    const joined = new Uint8Array(encryption.length + signing.length);
    joined.set(encryption, 0);
    joined.set(signing, encryption.length);

    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', joined));

    return Array.from(digest, byte => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Twee regels van acht groepjes: zo lees je hem in twee adem voor.
 */
function formatFingerprint(hex) {
    const groups = hex.toUpperCase().match(/.{4}/g) || [];

    return groups.slice(0, 8).join(' ') + (groups.length > 8 ? '\n' + groups.slice(8).join(' ') : '');
}

/* Berichten ----------------------------------------------------------------- */

/**
 * Versleutelt een bericht voor de ontvanger. Met een afzender wordt de envelop
 * ondertekend; zonder afzender is het bericht anoniem, ook voor de ontvanger.
 *
 * envelop:  { v, to, ts, body, from? }
 * payload:  { e: <envelop als JSON>, s?: <ECDSA-handtekening over e> }
 */
async function sealMessage(body, recipient, sender = null) {
    const envelope = {
        v: DD_ENVELOPE_VERSION,
        to: recipient.publicId,
        ts: Math.floor(Date.now() / 1000),
        body
    };

    if (sender) {
        envelope.from = sender.publicId;
    }

    const inner = JSON.stringify(envelope);
    const payload = { e: inner };

    if (sender) {
        const signature = await crypto.subtle.sign(DD_ECDSA_SIGN, sender.signKey, utf8(inner));
        payload.s = bytesToBase64(signature);
    }

    const iv = generateRandomBytes(12);
    const messageKey = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt']);
    const encryptedPayload = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, messageKey, utf8(JSON.stringify(payload)));
    const recipientKey = await crypto.subtle.importKey('spki', base64ToBytes(recipient.publicKey), DD_RSA, false, ['encrypt']);
    const encryptedMessageKey = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, recipientKey, await crypto.subtle.exportKey('raw', messageKey));

    return {
        encrypted_payload: bytesToBase64(encryptedPayload),
        iv: bytesToBase64(iv),
        salt: bytesToBase64(encryptedMessageKey)
    };
}

/**
 * Ontsleutelt een bericht. Berichten van vóór de envelop (versie 0.3) komen
 * terug als 'legacy': platte tekst, zonder afzender of handtekening.
 */
async function unsealMessage(sealed, decryptKey) {
    const rawMessageKey = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, decryptKey, base64ToBytes(sealed.salt));
    const messageKey = await crypto.subtle.importKey('raw', rawMessageKey, { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
    const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: base64ToBytes(sealed.iv) }, messageKey, base64ToBytes(sealed.encrypted_payload));
    const text = new TextDecoder().decode(plain);

    try {
        const payload = JSON.parse(text);

        if (payload && typeof payload.e === 'string') {
            return {
                format: 'envelope',
                inner: payload.e,
                envelope: JSON.parse(payload.e),
                signature: typeof payload.s === 'string' ? payload.s : null
            };
        }
    } catch (error) {
        // Geen JSON: oud berichtformaat.
    }

    return { format: 'legacy', inner: text, envelope: { body: text }, signature: null };
}

async function verifyEnvelopeSignature(inner, signatureBase64, signingPublicKey) {
    if (!signatureBase64 || !signingPublicKey) {
        return false;
    }

    const key = await crypto.subtle.importKey('spki', base64ToBytes(signingPublicKey), DD_ECDSA, false, ['verify']);

    return crypto.subtle.verify(DD_ECDSA_SIGN, key, base64ToBytes(signatureBase64), utf8(inner));
}

/* Private pass ------------------------------------------------------------ */

/**
 * Grove sterkte-inschatting: lengte en variatie. Geen garantie, wel een
 * zichtbare duw richting een lange pass.
 */
function estimatePassStrength(pass) {
    if (!pass) {
        return { score: 0, label: '' };
    }

    let pool = 0;
    if (/[a-z]/.test(pass)) pool += 26;
    if (/[A-Z]/.test(pass)) pool += 26;
    if (/[0-9]/.test(pass)) pool += 10;
    if (/[^a-zA-Z0-9]/.test(pass)) pool += 33;

    const unique = new Set(pass).size;
    const bits = Math.log2(Math.max(pool, 1)) * Math.min(pass.length, unique * 2);

    if (bits < 50) return { score: 1, label: 'zwak: maak hem langer' };
    if (bits < 70) return { score: 2, label: 'redelijk' };
    if (bits < 90) return { score: 3, label: 'sterk' };
    return { score: 4, label: 'zeer sterk' };
}
