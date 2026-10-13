// End-to-end-test van Deaddrop in een echte (headless) Chrome via DevTools Protocol.
// Draait tegen een lege testdatabase; zie tests/README.md.
//
//   node tests/e2e.mjs http://127.0.0.1:8766
//
// Database-controles gaan via `php tests/db.php "<sql>"` met dezelfde env.
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = process.argv[2] || 'http://127.0.0.1:8766';
const here = dirname(fileURLToPath(import.meta.url));
const PHP = process.env.PHP || 'php';
const PASSWORD = 'test-wachtwoord-123';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const port = 9341;
const sleep = ms => new Promise(r => setTimeout(r, ms));

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? '  (' + detail + ')' : ''}`);
}
function sql(query) {
  return JSON.parse(execFileSync(PHP, [join(here, 'db.php'), query], { encoding: 'utf8' }));
}

const prof = mkdtempSync(join(tmpdir(), 'dd-e2e-'));
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${prof}`, 'about:blank'], { stdio: 'ignore' });

let wsUrl;
for (let i = 0; i < 50 && !wsUrl; i++) {
  await sleep(200);
  try { wsUrl = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find(t => t.type === 'page')?.webSocketDebuggerUrl; } catch {}
}
const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const waiters = []; const consoleErrors = [];
ws.addEventListener('message', e => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') consoleErrors.push(m.params.entry.text);
  if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
  if (m.method) waiters.filter(w => w.method === m.method).forEach(w => { w.resolve(m); waiters.splice(waiters.indexOf(w), 1); });
});
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));
const evalJs = async expr => {
  const res = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (res.result?.exceptionDetails) throw new Error(res.result.exceptionDetails.exception?.description || 'eval error');
  return res.result?.result?.value;
};
async function go(path) { const l = once('Page.loadEventFired'); await send('Page.navigate', { url: base + path }); await Promise.race([l, sleep(8000)]); await sleep(150); }
async function waitFor(expr, timeout = 20000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) { try { if (await evalJs(expr)) return true; } catch {} await sleep(150); }
  return false;
}
async function submitAndWait(expr) { const l = once('Page.loadEventFired'); await evalJs(expr); await Promise.race([l, sleep(8000)]); await sleep(150); }
const path = () => evalJs('location.pathname.split("/").pop()');
const text = sel => evalJs(`document.querySelector(${JSON.stringify(sel)})?.textContent?.trim() || ''`);

async function login(username, password = PASSWORD) {
  await send('Network.clearBrowserCookies');
  await go('/login.php');
  await submitAndWait(`document.querySelector('#username').value=${JSON.stringify(username)};document.querySelector('#password').value=${JSON.stringify(password)};document.querySelector('#password').form.submit()`);
}
async function keySetup(pass) {
  await evalJs(`document.querySelector('#private_pass').value=${JSON.stringify(pass)};document.querySelector('#private_pass_repeat').value=${JSON.stringify(pass)};document.querySelector('[data-key-setup-form] button').click()`);
  return waitFor(`location.pathname.endsWith('profile.php')`, 30000);
}
async function channelRef() {
  await go('/dashboard.php');
  return evalJs(`new URL(document.querySelector('a[href*="deaddrop.php?ref="]').href).searchParams.get('ref')`);
}
async function compose(ref, recipient, body, pass, { anonymous = false } = {}) {
  await go(`/message_create.php?drop=${ref}`);
  await evalJs(`const s=document.querySelector('#recipient_ref');s.value=[...s.options].find(o=>o.textContent.trim()===${JSON.stringify(recipient)}).value;s.dispatchEvent(new Event('change'))`);
  await waitFor(`!document.querySelector('#recipient_key').classList.contains('is-hidden') || !document.querySelector('#message_encrypt_error').classList.contains('is-hidden')`);
  const keyStatus = await evalJs(`document.querySelector('#recipient_key').dataset.status || ''`);
  await evalJs(`document.querySelector('#message').value=${JSON.stringify(body)};${anonymous ? "document.querySelector('#send_anonymous').click();" : `document.querySelector('#sender_pass').value=${JSON.stringify(pass)};`}document.querySelector('[data-encrypted-message-form] button[type=submit]').click()`);
  const sent = await waitFor(`location.pathname.endsWith('message_created.php') || !document.querySelector('#message_encrypt_error').classList.contains('is-hidden')`, 30000);
  return { keyStatus, created: sent && (await path()) === 'message_created.php', error: await text('#message_encrypt_error') };
}
async function openLatest(ref) {
  await go(`/deaddrop.php?ref=${ref}`);
  const href = await evalJs(`document.querySelector('a[href*="message_open.php"]')?.getAttribute('href')`);
  return href ? new URL(href, base + '/').searchParams.get('ref') : null;
}
async function tryDecrypt(pass) {
  await evalJs(`document.getElementById('decrypt_error').classList.add('is-hidden');document.querySelector('#private_pass').value=${JSON.stringify(pass)};document.querySelector('[data-decrypt-message-form] button').click()`);
  await waitFor(`!document.querySelector('#decrypt_error').classList.contains('is-hidden') || !document.querySelector('#message_box').classList.contains('is-hidden')`, 30000);
  await waitFor(`!document.querySelector('#sender_check').classList.contains('is-hidden') || !document.querySelector('#decrypt_error').classList.contains('is-hidden')`, 10000);
}

await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable'); await send('Network.enable');

// 1. Verificatiepagina is publiek en de node start
await go('/verify.php');
check('verify.php is publiek bereikbaar', (await text('h1')) === 'Controleer deze node');

// 2. Sleutels aanmaken
await login('alice');
check('nieuwe gebruiker gaat naar key setup', (await path()) === 'key_setup.php');
check('key setup voor alice', await keySetup('alice-private-pass-lang'));
await waitFor(`!document.querySelector('#own_fingerprint').textContent.includes('berekend')`);
const aliceFp = await text('#own_fingerprint');
check('profiel toont fingerprint (64 hex in groepjes)', /^([0-9A-F]{4}\s){15}[0-9A-F]{4}$/.test(aliceFp), aliceFp);
const aliceKeys = sql(`SELECT kdf_iterations, signing_public_key IS NOT NULL AS signing FROM user_keys uk JOIN users u ON u.id=uk.user_id WHERE u.username='alice'`)[0];
check('nieuwe sleutels: 600.000 iteraties en ondertekensleutel', Number(aliceKeys.kdf_iterations) === 600000 && Number(aliceKeys.signing) === 1);

await login('bob');
check('key setup voor bob', await keySetup('bob-private-pass-lang'));
await login('carol');
check('key setup voor carol', await keySetup('carol-private-pass-lang'));

// 3. Ondertekend bericht van alice naar bob
await login('alice');
const ref = await channelRef();
const first = await compose(ref, 'bob', 'Het pakket ligt bij de achterdeur.', 'alice-private-pass-lang');
check('alice ziet bob als eerste contact', first.keyStatus === 'new', first.keyStatus);
check('bericht versleuteld en opgeslagen', first.created, first.error);
const row = sql(`SELECT encrypted_payload, created_at, created_bucket, status FROM messages ORDER BY id DESC LIMIT 1`)[0];
check('database bevat geen platte tekst', !String(row.encrypted_payload).includes('achterdeur') && !atob(row.encrypted_payload).includes('achterdeur'));
check('created_at is afgerond op 5 minuten', row.created_at === row.created_bucket && /:(00|05|10|15|20|25|30|35|40|45|50|55):00$/.test(row.created_at), row.created_at);
check('messages heeft geen afzenderkolom', sql(`SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME='sender_user_id'`)[0].n == 0);

// 4. Bob opent: eerst een foute pass, dan de goede
await login('bob');
const msgRef = await openLatest(ref);
await go(`/message_open.php?ref=${msgRef}`);
check('eerste weergave zet status op reading', sql(`SELECT status FROM messages WHERE public_id='${msgRef}'`)[0].status === 'reading');
await tryDecrypt('verkeerde-pass-1234');
check('foute pass: nog 4 pogingen', (await text('#decrypt_error')).includes('Nog 4'), await text('#decrypt_error'));
check('mislukte poging staat serverside', Number(sql(`SELECT failed_attempts FROM messages WHERE public_id='${msgRef}'`)[0].failed_attempts) === 1);
await tryDecrypt('bob-private-pass-lang');
check('goede pass: tekst zichtbaar', (await text('#decrypted_output')) === 'Het pakket ligt bij de achterdeur.');
check('afzender ondertekend en geverifieerd als alice', (await text('#sender_title')) === 'Ondertekend door alice', await text('#sender_title'));
await sleep(500);
const opened = sql(`SELECT status, encrypted_payload IS NULL AS wiped FROM messages WHERE public_id='${msgRef}'`)[0];
check('na lezen: status opened en ciphertext gewist', opened.status === 'opened' && Number(opened.wiped) === 1, JSON.stringify(opened));
await go(`/message_open.php?ref=${msgRef}`);
check('tweede weergave: al geopend, geen ciphertext in de pagina', (await text('h2')) === 'Al geopend' && !(await evalJs(`!!document.querySelector('#encrypted_payload')`)));

// 5. Herladen tijdens het leesvenster = weg
await login('alice');
await compose(ref, 'bob', 'Tweede bericht', 'alice-private-pass-lang');
await login('bob');
const msg2 = await openLatest(ref);
await go(`/message_open.php?ref=${msg2}`);
await go(`/message_open.php?ref=${msg2}`);
check('herladen tijdens leesvenster toont het bericht niet opnieuw', (await text('h2')) === 'Al geopend');

// 6. Vijf foute pogingen = vergrendeld en gewist
await login('alice');
await compose(ref, 'bob', 'Derde bericht', 'alice-private-pass-lang');
await login('bob');
const msg3 = await openLatest(ref);
await go(`/message_open.php?ref=${msg3}`);
for (let i = 1; i <= 5; i++) await tryDecrypt(`fout-${i}-xxxxxxxx`);
const locked = sql(`SELECT status, encrypted_payload IS NULL AS wiped FROM messages WHERE public_id='${msg3}'`)[0];
check('na 5 foute passes: locked en gewist', locked.status === 'locked' && Number(locked.wiped) === 1, JSON.stringify(locked));

// 7. Anoniem bericht
await login('carol');
const anon = await compose(ref, 'bob', 'Zonder naam', null, { anonymous: true });
check('anoniem bericht zonder pass verstuurd', anon.created, anon.error);
await login('bob');
const msg4 = await openLatest(ref);
await go(`/message_open.php?ref=${msg4}`);
await tryDecrypt('bob-private-pass-lang');
check('anoniem bericht: leesbaar en gemarkeerd als niet ondertekend', (await text('#decrypted_output')) === 'Zonder naam' && (await text('#sender_title')) === 'Anoniem bericht', await text('#sender_title'));

// 8. Operator vervangt bobs sleutel door die van carol: alice moet geblokkeerd worden
sql(`UPDATE user_keys bk JOIN users b ON b.id=bk.user_id AND b.username='bob' JOIN (SELECT uk.public_key, uk.signing_public_key FROM user_keys uk JOIN users c ON c.id=uk.user_id AND c.username='carol') ck SET bk.public_key=ck.public_key, bk.signing_public_key=ck.signing_public_key`);
await login('alice');
const attacked = await compose(ref, 'bob', 'Dit mag niet aankomen', 'alice-private-pass-lang');
check('gewijzigde sleutel van bob wordt gedetecteerd', attacked.keyStatus === 'changed', attacked.keyStatus);
check('versturen naar gewijzigde sleutel is geblokkeerd', !attacked.created && attacked.error.includes('veranderd'), attacked.error);
sql(`DELETE FROM known_keys`);
const afterWipe = await compose(ref, 'bob', 'Dit mag ook niet aankomen', 'alice-private-pass-lang');
check('ook na leegmaken van known_keys blijft de lokale pin blokkeren', afterWipe.keyStatus === 'changed' && !afterWipe.created, afterWipe.keyStatus);

// 9. Login-throttling
await send('Network.clearBrowserCookies');
for (let i = 0; i < 7; i++) {
  await go('/login.php');
  await submitAndWait(`document.querySelector('#username').value='bestaatniet';document.querySelector('#password').value='fout';document.querySelector('#password').form.submit()`);
}
check('na 5 foute logins volgt een lockout', (await text('.notice.error')).includes('Te veel mislukte pogingen'), await text('.notice.error'));
check('throttle bewaart alleen HMACs', sql(`SELECT COUNT(*) AS n FROM auth_throttle WHERE identifier_hash LIKE '%bestaatniet%' OR identifier_hash LIKE '%127.0.0.1%'`)[0].n == 0);

// 10. Oude sleutels (250.000 iteraties, zonder ondertekensleutel) upgraden
await login('carol');
await go('/profile.php');
const upgrade = await evalJs(`(async () => {
  const pass = 'oude-pass-1234567';
  const enc = await generateEncryptionKeyPair();
  const salt = generateRandomBytes(16);
  const key = await deriveWrappingKey(pass, salt, 250000);
  const wrapped = await wrapBytes(key, enc.pkcs8);
  const legacy = { public_key: enc.publicKey, encrypted_private_key: wrapped.data, private_key_iv: wrapped.iv, private_key_salt: bytesToBase64(salt), kdf_iterations: 250000, kdf_algo: 'PBKDF2-SHA256' };
  const opened = await openKeyRecord(legacy, pass);
  const up = await upgradeKeyRecord(opened, pass);
  const again = await openKeyRecord(up.record, pass);
  return { needed: opened.needsUpgrade, iterations: up.record.kdf_iterations, signing: !!again.signKey, samePublic: up.record.public_key === legacy.public_key };
})()`);
check('oude sleutels worden herkend en geüpgraded', upgrade.needed && upgrade.iterations === 600000 && upgrade.signing && upgrade.samePublic, JSON.stringify(upgrade));

// 11. Pass wijzigen houdt de fingerprint gelijk
await login('alice');
await go('/profile.php');
await waitFor(`!document.querySelector('#own_fingerprint').textContent.includes('berekend')`);
const before = await text('#own_fingerprint');
await evalJs(`document.querySelector('#rewrap_old').value='alice-private-pass-lang';document.querySelector('#rewrap_new').value='alice-nieuwe-pass-langer';document.querySelector('#rewrap_repeat').value='alice-nieuwe-pass-langer';document.querySelector('#rewrap_form button').click()`);
await waitFor(`document.querySelector('#profile_status').textContent.includes('gewijzigd') || !document.querySelector('#profile_error').classList.contains('is-hidden')`, 30000);
check('pass gewijzigd, fingerprint gelijk', (await text('#own_fingerprint')) === before && (await text('#profile_status')).includes('gewijzigd'), await text('#profile_error'));
const rewrapOk = await evalJs(`(async()=>{const me=await ddFetchMyKeys();try{await openKeyRecord(me.record,'alice-private-pass-lang');return 'oud werkt nog'}catch(e){}const o=await openKeyRecord(me.record,'alice-nieuwe-pass-langer');return o.signKey?'ok':'geen signkey'})()`);
check('oude pass werkt niet meer, nieuwe wel', rewrapOk === 'ok', rewrapOk);

// 11b. Accountwachtwoord wijzigen
await go('/profile.php');
await submitAndWait(`document.querySelector('#current_password').value='${PASSWORD}';document.querySelector('#new_password').value='alice-account-nieuw-123';document.querySelector('#new_password_repeat').value='alice-account-nieuw-123';document.querySelector('#current_password').form.submit()`);
check('accountwachtwoord gewijzigd', (await text('#wachtwoord .notice')).includes('gewijzigd'), await text('#wachtwoord .notice'));
await login('alice', 'alice-account-nieuw-123');
check('inloggen met nieuw accountwachtwoord', (await path()) === 'dashboard.php', await path());

// 11c. verify.php met release-manifest
execFileSync(PHP, [join(here, '..', 'tools', 'build_manifest.php')]);
await go('/verify.php');
const verifyState = await evalJs(`[...document.querySelectorAll('.verify-table td:last-child')].map(td => td.textContent.trim())`);
check('verify.php: alle bestanden gelijk aan het manifest', verifyState.length > 40 && verifyState.every(s => s === 'gelijk'), `${verifyState.length} bestanden`);
const sri = await evalJs(`document.querySelector('link[rel=stylesheet]').getAttribute('integrity')`);
check('stylesheet heeft integrity uit het manifest', /^sha384-/.test(sri || ''));

// 12. Terminal laadt zonder fouten
await go('/terminal.php');
check('terminal start', (await evalJs(`document.querySelector('#output').textContent`)).includes('DEADDROP TERMINAL v0.4'));

// 13. Cleanup-cron: verlopen bericht verliest ciphertext
await login('alice', 'alice-account-nieuw-123');
await compose(ref, 'carol', 'Verloopt zo', 'alice-nieuwe-pass-langer');
sql(`UPDATE messages SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE status='unopened'`);
execFileSync(PHP, [join(here, '..', 'cron', 'cleanup_expired.php')]);
const expired = sql(`SELECT COUNT(*) AS n FROM messages WHERE status='unopened' OR (status='expired' AND encrypted_payload IS NOT NULL)`)[0];
check('cleanup: verlopen berichten gewist', Number(expired.n) === 0);

// 14. Geen CSP-schendingen of JS-fouten
const relevant = consoleErrors.filter(e => !/401|403|404|favicon/.test(e));
check('geen CSP-schendingen of JS-fouten in de console', relevant.length === 0, relevant.slice(0, 3).join(' | '));

execFileSync('rm', ['-f', join(here, '..', 'release-manifest.json')]);
ws.close(); chrome.kill();
const failed = results.filter(r => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} geslaagd`);
process.exit(failed ? 1 : 0);
