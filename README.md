# Deaddrop

Een self-hosted berichtensysteem voor kleine groepen, waarin berichten in de browser van
de afzender worden versleuteld en pas in de browser van de ontvanger weer leesbaar worden.
De server bewaart alleen ciphertext. Een bericht is na één keer lezen weg, en verlopen
berichten verdwijnen helemaal uit de database.

Gewone PHP en JavaScript, zonder framework, Composer, npm of buildstap. De code in deze
repository is letterlijk de code die draait.

## Wat het doet

- **Versleuteling in de browser.** Elke gebruiker heeft een RSA-OAEP-4096-sleutelpaar.
  Elk bericht krijgt een eigen AES-256-GCM-sleutel, versleuteld voor de ontvanger. Alles via
  de Web Crypto API van de browser.
- **Ondertekend, maar niet voor de server.** Een tweede sleutelpaar (ECDSA P-256) ondertekent
  het bericht. De handtekening zit ín de versleutelde envelop: de server ziet niet wie iets
  stuurde, de ontvanger kan controleren dat het echt van die persoon komt. Wie dat niet wil,
  verstuurt anoniem (zonder handtekening).
- **Private pass.** De private keys staan op de server, ingepakt met een sleutel die uit je
  private pass wordt afgeleid (PBKDF2-SHA256, 600.000 iteraties). De pass zelf verlaat je
  browser nooit.
- **Fingerprints en pinning.** Per contact een fingerprint over beide public keys, om buiten
  Deaddrop te vergelijken. De browser onthoudt welke sleutel hij eerder zag, in de database
  én lokaal. Verandert een sleutel, dan blokkeert het versturen tot je opnieuw controleert.
- **Burn after read, serverside.** Bij de eerste weergave gaat de ciphertext één keer naar de
  browser. Na ontsleutelen, na 5 minuten of na 5 verkeerde passes wist de server hem.
- **Minder metadata.** Geen afzenderkolom, tijdstempels afgerond op 5 minuten of op de dag,
  throttling met HMACs in plaats van IP-adressen, en een cronjob die oude rijen verwijdert.
- **Trusted devices.** Optioneel: inloggen alleen vanaf apparaten die een beheerder met een
  eenmalige code heeft geactiveerd.
- **Twee interfaces.** Een gewone webinterface en een terminal, op dezelfde acties.
- **Controleerbare levering.** Elke release heeft een manifest met hashes van alle bestanden;
  `verify.php` toont wat een node echt uitlevert.

## Wat het niet doet

Lees [docs/threat-model.md](docs/threat-model.md) voordat je Deaddrop ergens voor gebruikt
waar veel van afhangt. Kort:

- **De node levert de JavaScript.** Wie de node beheert, kan die aanpassen en zo je private
  pass onderscheppen. Het manifest en `verify.php` maken dat zichtbaar, niet onmogelijk.
- **Metadata blijft deels zichtbaar** voor de beheerder: wie in welk channel zit, hoeveel
  berichten er klaarstaan, wanneer iemand actief is.
- **Geen forward secrecy.** Wie ooit een private key én oude ciphertext bemachtigt, kan die
  lezen. Burn after read en de cleanup beperken hoeveel oude ciphertext er bestaat.
- **Gedeelde hosting is geen veilige node.** Draai een echte node op een server die je zelf beheert.

## Eisen

| | |
|---|---|
| PHP | 8.1 of hoger, met `pdo_mysql` |
| Database | MariaDB 10.4+ of MySQL 8.0+ |
| Webserver | Apache, Nginx, Caddy of LiteSpeed, met `public/` als document root |
| HTTPS | Verplicht: de Web Crypto API werkt alleen in een secure context |
| Cron | Elke 5 minuten `cron/cleanup_expired.php` |
| Schijf | Een paar MB, plus een schrijfbare map `storage/` voor sessies |

## Installeren

Clone de repository, of download de zip van de nieuwste [release](https://github.com/Burg-Media/deaddrop/releases):

```bash
git clone https://github.com/Burg-Media/deaddrop.git
cd deaddrop
cp .env.example .env              # vul APP_URL, de database en APP_KEY in
php tools/generate_app_key.php    # zet de uitvoer in APP_KEY
php tools/migrate.php             # maakt alle tabellen
```

Daarna de eerste beheerder, de webserver, HTTPS en de cronjob: stap voor stap in
[docs/install.md](docs/install.md), met voorbeelden voor Apache, Nginx, Caddy en Plesk.

## Documentatie

- [docs/install.md](docs/install.md): installatie, eerste beheerder, cron, updates
- [docs/threat-model.md](docs/threat-model.md): waartegen het beschermt en waartegen niet
- [docs/verify.md](docs/verify.md): controleren welke code een node uitlevert
- [docs/reference-architecture.md](docs/reference-architecture.md): drie manieren om een node neer te zetten
- [docs/operator.md](docs/operator.md): rollen, trusted devices en herstel voor de beheerder
- [SECURITY.md](SECURITY.md): een kwetsbaarheid melden
- [CHANGELOG.md](CHANGELOG.md)

## Testen

`tests/e2e.mjs` loopt de hele keten door in een echte Chrome: sleutels maken, ondertekend
versturen, burn after read, lockout, sleutelvervanging door de server, throttling, upgrades
van oude sleutels, pass wijzigen en de cleanup. Zie [tests/README.md](tests/README.md).

## Licentie

[GNU Affero General Public License v3.0](LICENSE). Je mag Deaddrop gebruiken, aanpassen en
verspreiden. Bied je een aangepaste versie aan anderen aan, ook als webdienst, dan moet je
hun de broncode van jouw versie geven (zet `APP_SOURCE_URL` in `.env` naar die plek).

## Gemaakt door

[Burg Media](https://burgmedia.eu).
