# Installatie

Een Deaddrop-node is een gewone PHP-applicatie met een MySQL- of MariaDB-database.
Geen Composer, geen npm, geen buildstap: je zet de bestanden neer, vult `.env` in,
draait de migraties en wijst de webserver naar `public/`.

## 1. Eisen

- PHP 8.1 of hoger, met `pdo_mysql` (op Debian: `apt install php-fpm php-mysql`)
- MariaDB 10.4+ of MySQL 8.0+
- Een webserver die `public/` als document root kan serveren
- HTTPS met een geldig certificaat. Zonder HTTPS weigert de browser de Web Crypto API,
  en kan niemand sleutels maken of berichten openen.
- Cron (of een systemd-timer) voor de opruimtaak

## 2. Bestanden

```bash
cd /var/www
git clone --branch v0.4.0 https://github.com/Burg-Media/deaddrop.git deaddrop
cd deaddrop
```

Zonder git: download de zip van de release op
[github.com/Burg-Media/deaddrop/releases](https://github.com/Burg-Media/deaddrop/releases) en pak hem uit.

Alleen `public/` hoort via het web bereikbaar te zijn. `.env`, `config/`, `includes/`,
`actions/`, `database/`, `tools/`, `cron/` en `storage/` staan erbuiten.
(`public/actions/` bestaat wel: dat zijn kleine doorverwijzingen naar `actions/`.)

De webserver moet in `storage/` kunnen schrijven (sessies):

```bash
mkdir -p storage/sessions
chown -R www-data:www-data storage
chmod 700 storage storage/sessions
```

## 3. Database

```sql
CREATE DATABASE deaddrop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'deaddrop'@'localhost' IDENTIFIED BY '<lang wachtwoord>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
    ON deaddrop.* TO 'deaddrop'@'localhost';
```

Draait de database op een andere machine, zet dan de host in plaats van `localhost`,
en laat de database alleen luisteren op het interne netwerk (nooit op internet).

## 4. Configuratie

```bash
cp .env.example .env
php tools/generate_app_key.php
```

Vul in `.env` in:

| Sleutel | Waarde |
|---|---|
| `APP_URL` | Exact de URL in de adresbalk, bijvoorbeeld `https://drop.example.org` |
| `APP_COOKIE_SECURE` | `true` (zet ook HSTS aan) |
| `APP_KEY` | De uitvoer van `generate_app_key.php`. Bewaar hem: verandert hij, dan zijn alle trusted devices ongeldig. |
| `DB_*` | De database uit stap 3 |
| `APP_DEVICE_TRUST_REQUIRED` | Voorlopig `false`, zie stap 8 |

Wachtwoorden met speciale tekens zet je tussen dubbele quotes.

Ontbreekt er iets of staat er nog een voorbeeldwaarde, dan start de node niet en toont
hij welke instelling niet klopt.

## 5. Migraties

```bash
php tools/migrate.php
php tools/migrate.php --status     # controleren
```

Op een server met meerdere PHP-versies (zoals Plesk) gebruik je overal de juiste binary,
bijvoorbeeld `/opt/plesk/php/8.3/bin/php` in plaats van `php`.

## 6. Webserver

### Nginx met PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name drop.example.org;
    root /var/www/deaddrop/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/drop.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/drop.example.org/privkey.pem;

    # Geen toegangslog: dat is een van de grootste metadatalekken.
    access_log off;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}

server {
    listen 80;
    server_name drop.example.org;
    return 301 https://$host$request_uri;
}
```

### Apache

```apache
<VirtualHost *:443>
    ServerName drop.example.org
    DocumentRoot /var/www/deaddrop/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/drop.example.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/drop.example.org/privkey.pem

    <Directory /var/www/deaddrop/public>
        AllowOverride None
        Require all granted
    </Directory>

    # Geen toegangslog
    CustomLog /dev/null common
</VirtualHost>
```

### Caddy

```caddy
drop.example.org {
    root * /var/www/deaddrop/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    log {
        output discard
    }
}
```

Caddy regelt het certificaat zelf.

### Plesk (ook met LiteSpeed)

- Zet de projectmap in `~/<domein>/` en de document root in Plesk op `<domein>/public`.
- Kies PHP 8.1 of hoger bij het domein.
- Zet Let's Encrypt aan en forceer HTTPS.
- Upload geen `.htaccess` boven `public/`: LiteSpeed leest die ook buiten de document root.
- `DB_PORT=3306` in `.env` (niet de poort van een lokale ontwikkelomgeving).
- Gebruik in cronjobs en via SSH de Plesk-PHP: `/opt/plesk/php/<versie>/bin/php`.
- Let op: Plesk is gedeelde hosting als andere klanten op dezelfde server zitten. Prima
  voor een demo, niet voor een node waar echte geheimen doorheen gaan (zie het threat model).

## 7. Cron

Elke 5 minuten:

```cron
*/5 * * * * www-data /usr/bin/php /var/www/deaddrop/cron/cleanup_expired.php
```

Dit wist de ciphertext van verlopen berichten en verlopen leesvensters, verwijdert na
24 uur de rijen zelf, en ruimt oude activatiecodes, ingetrokken devices en throttling op.
Met `--verbose` zie je wat hij deed.

## 8. Eerste beheerder en trusted devices

```bash
php tools/create_user.php jouwnaam --role=superadmin
```

Log in met het getoonde tijdelijke wachtwoord. Je browser vraagt je een private pass te
kiezen en maakt je sleutels. Wijzig daarna op de pagina Sleutels je accountwachtwoord en
download een back-up van je sleutels.

Wil je dat inloggen alleen vanaf geactiveerde apparaten kan:

1. Maak in Devices (of in de terminal: `create device`) een activatiecode voor je eigen account.
2. Open `/activate.php` op dat apparaat en voer de code in.
3. Controleer in Devices dat het apparaat als trusted staat.
4. Zet pas dan `APP_DEVICE_TRUST_REQUIRED=true` in `.env`.

Codes voor beheeraccounts, en codes zonder vaste gebruiker, maakt alleen een superadmin.

## 9. Controleren

```bash
curl -I https://drop.example.org/login.php
```

Verwacht onder meer `content-security-policy`, `strict-transport-security` en
`x-frame-options: DENY`. Open daarna `/verify.php`: daar staat de draaiende versie en of
de bestanden overeenkomen met het release-manifest.

Node Admin (alleen voor superadmins) laat dezelfde controles zien plus de stand van de
migraties.

## Bijwerken

```bash
git fetch --tags
git checkout v<nieuwe versie>
php tools/migrate.php
```

Met een zip: pak de nieuwe release uit over de bestaande map heen (`.env` en
`storage/` blijven staan) en draai daarna de migraties.

Controleer daarna `/verify.php`.

### Van versie 0.3 naar 0.4

Een node uit mei 2026 heeft de tabellen al, maar nog geen migratieregistratie:

```bash
php tools/migrate.php --baseline=005
php tools/migrate.php
```

Bestaande sleutels werken gewoon door. Bij de eerstvolgende keer dat iemand zijn private
pass invult, brengt de browser zijn sleutels naar 600.000 iteraties en maakt hij er een
ondertekensleutel bij. Daardoor verandert die fingerprint één keer, en zien contacten
eenmalig een waarschuwing. Vergelijk de nieuwe fingerprint en bevestig hem.
