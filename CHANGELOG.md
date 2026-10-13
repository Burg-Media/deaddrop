# Changelog

Functionele wijzigingen, security-relevante keuzes en database-migraties.
Installatie en upgrades: zie docs/install.md.

## 0.4.0 (oktober 2026)

Het bouwplan na de health check: dichtzetten, crypto afmaken, installeerbaar maken.

### Crypto
- Tweede sleutelpaar per gebruiker: ECDSA P-256 om berichten te ondertekenen.
- Berichten in een envelop `{v, to, ts, body, from}`, ondertekend, en die envelop versleuteld.
  De server ziet geen afzender; de ontvanger kan de handtekening controleren. Bindt aan
  ontvanger en tijdstip, dus niet door te sturen alsof hij voor een ander was.
- Anoniem versturen (zonder handtekening) als uitdrukkelijke keuze.
- Fingerprints (SHA-256 over beide public keys), in de browser berekend.
- Pinning: de browser onthoudt per contact de fingerprint, in `known_keys` én in localStorage.
  Een gewijzigde sleutel blokkeert het versturen tot je hem opnieuw controleert.
- PBKDF2 van 250.000 naar 600.000 iteraties, met `kdf_algo` en `kdf_iterations` per gebruiker.
  Oude sleutels worden bij de eerstvolgende ontsleuteling bijgewerkt.
- Pagina Sleutels: eigen fingerprint, private pass wijzigen (fingerprint blijft gelijk),
  back-up downloaden en terugzetten, nieuw sleutelpaar, contacten, accountwachtwoord.
- Terminal: compose ondertekent (of `anon`), toont de fingerprint van de ontvanger en blokkeert
  bij een gewijzigde sleutel. Nieuwe commando's `fingerprint`, `verify` en `keys`.

### Burn after read en opruimen
- Toestandsmachine serverside: de eerste weergave zet een bericht op `reading` en levert de
  ciphertext één keer uit. Na ontsleutelen (`opened`), na 5 minuten (`destroyed`) of na
  5 verkeerde passes (`locked`) wist de server hem.
- `cron/cleanup_expired.php` (was leeg): wist verlopen ciphertext, verwijdert rijen na 24 uur,
  ruimt activatiecodes, ingetrokken devices en throttling op.

### Hardening
- Throttling op login (per username en per IP), device-activatie en berichtaanmaak, met HMACs
  in plaats van leesbare IP's of usernames. De oude lockout in de sessie (en de foutmelding
  die uitlegde hoe je hem omzeilde) is weg.
- Login kost even lang voor bestaande en niet-bestaande usernames.
- Alleen een superadmin maakt activatiecodes voor beheeraccounts of zonder vaste gebruiker.
- Berichten: strikte base64-controle en maximale grootte.
- Fail-fast configuratie: zonder geldige `APP_URL`, `APP_KEY` (minimaal 32 tekens, geen
  voorbeeldwaarde) of database start de node niet, met een nette melding.
- Sessies: strikte modus, eigen opslag in `storage/`, idle-timeout en maximale levensduur.
- CSP zonder `unsafe-inline`; HSTS bij secure cookies; `Cache-Control: no-store`.
- Minder metadata: `messages.created_at` gelijk aan de 5-minutenbucket, `last_seen_at` en
  `last_login_at` op de dag, kolom `messages.sender_user_id` verwijderd.

### Verifieerbare levering
- `tools/build_manifest.php` maakt `release-manifest.json` met hashes van alle bestanden.
- Alle scripts en de stylesheet met Subresource Integrity.
- Publieke pagina `verify.php`: versie, manifest en live hashes per bestand.

### Installeerbaar
- Basisschema (`000_base_schema.sql`) en genummerde migraties in `database/migrations/`.
- `tools/migrate.php` (met `--status` en `--baseline` voor bestaande nodes),
  `tools/generate_app_key.php`, `tools/create_user.php`.
- Documentatie: README, threat model, installatie, verificatie, referentie-architectuur,
  beheerdershandleiding, SECURITY.md.
- End-to-end-test in Chrome (`tests/`).

### Opgeruimd
- Lege bestanden `actions/fetch_message.php` en `dbchanges.ttxt` weg.

### Migraties
- `006_drop_message_sender.php`
- `007_messages_status_and_bucket.sql`
- `008_create_auth_throttle.sql`
- `009_user_keys_signing_and_kdf.sql`
- `010_create_known_keys.sql`

## 0.3 (mei 2026)

### 15 mei 2026
- E2EE key setup toegevoegd.
- Private-code-per-bericht flow vervangen door user-keypair basis.
- Message create omgebouwd naar recipient public key encryptie.
- Message open omgebouwd naar decryptie met private pass.
- Terminal workflow opgeschoond en contextuele help/suggesties toegevoegd.
- Admin channel create en member add flows aangescherpt.
- Dashboard en channel views tonen openstaande drops per channel.
- User management MVP toegevoegd met create user en soft-delete.
- Backend-validaties toegevoegd voor keypair-required recipients en gedeactiveerde users.
- Deploy-notitie toegevoegd: E2EE vereist HTTPS en `APP_URL` moet op `https://...` staan.
- Channel management uitgebreid met settings update en veilige member removal via simple view en terminal.

### 16 mei 2026
- Trusted device gate toegevoegd met activatiecodes, trusted device cookie en revoke-basis.
- Admin device management pagina toegevoegd met statusoverzicht, current-device check, pending/recent activatiecodes en snelle replacement-code flow.
- Terminal uitgebreid met `create device` en `devices`.
- Deploy-config uitgebreid met `APP_KEY`, `APP_DEVICE_TRUST_REQUIRED` en `APP_DEVICE_COOKIE_NAME`.
- Superadmin role en read-only Node Admin diagnostics toegevoegd.
- Node Action Key hash helper toegevoegd via `tools/hash_node_action_key.php`.
- Node Identity provisioning overzicht toegevoegd.
- Deploy- en beheerdersnotities toegevoegd (in 0.4 opgegaan in `docs/`).
