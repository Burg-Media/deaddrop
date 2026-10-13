# Voor de beheerder van een node

## Rollen

| Rol | Kan |
|---|---|
| **user** | Inloggen, eigen sleutels beheren, berichten versturen en openen in channels waar hij lid van is |
| **admin** | Daarnaast: channels maken en beheren, gebruikers aanmaken en deactiveren, activatiecodes voor gewone gebruikers |
| **superadmin** | Daarnaast: Node Admin en Identity, en activatiecodes voor beheeraccounts of zonder vaste gebruiker |

De eerste superadmin maak je met `php tools/create_user.php <naam> --role=superadmin`.

Een node werkt met vier lagen: HTTPS, een accountwachtwoord, eventueel een trusted device,
en de private pass. De eerste drie beheer jij; de vierde kent alleen de gebruiker.

## Wat je als beheerder niet kunt

- Berichten lezen. Je ziet in de database alleen ciphertext.
- Een vergeten private pass herstellen. Dat is het ontwerp. Laat gebruikers op de pagina
  Sleutels een back-up downloaden.
- Zien wie een bericht stuurde. Er is geen afzenderkolom; de afzender zit ondertekend in de
  versleutelde envelop.

## Node Admin en Identity

`/node_admin.php` (alleen superadmin) toont de configuratie (zonder geheimen), de stand van
de migraties, aantallen, en veiligheidscontroles: document root, `.env` buiten `public/`,
HTTPS, `APP_KEY`, release-manifest, sessieopslag.

`/node_identity.php` toont per gebruiker de accountstatus, of sleutels klaar zijn, trusted
devices, openstaande activatiecodes en channels.

Beide pagina's zijn alleen-lezen. Deploys, migraties, `.env` en herstarts doe je via SSH.

## Trusted devices

Een trusted device is een cookie `dd_device=<id>:<geheim>`. De database bewaart alleen een
HMAC van het geheim met `APP_KEY`.

- **Gekoppeld aan een gebruiker** (standaard): alleen die gebruiker logt in op dat apparaat.
- **Zonder vaste gebruiker**: elke actieve gebruiker. Alleen voor tijdelijk gebruik; alleen
  een superadmin maakt zo'n code.

Een device verliest zijn status als cookies worden gewist, het browserprofiel nieuw is, een
beheerder het intrekt, of `APP_KEY` verandert. Herstel: nieuwe activatiecode, `/activate.php`,
opnieuw inloggen.

### Alle devices kwijt

1. Via SSH: zet tijdelijk `APP_DEVICE_TRUST_REQUIRED=false` in `.env`.
2. Log in als superadmin en maak een activatiecode voor je eigen account.
3. Activeer je apparaat via `/activate.php`.
4. Zet `APP_DEVICE_TRUST_REQUIRED=true` terug.

## Throttling

Login: 5 mislukte pogingen per username en 20 per IP binnen 15 minuten, daarna een lockout
die per poging verdubbelt (maximaal een uur). Device-activatie: 10 per uur per IP. Berichten:
30 per 10 minuten per gebruiker. Alles staat als HMAC in `auth_throttle` en verdwijnt via de
cronjob na een uur.

## Gebruikers verwijderen

Deactiveren is een soft-delete: de gebruiker kan niet meer inloggen en verdwijnt uit
keuzelijsten. Je kunt jezelf niet deactiveren, en niet de laatste admin of superadmin.
