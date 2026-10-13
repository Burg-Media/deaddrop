# Referentie-architectuur

Drie manieren om een node neer te zetten, van eenvoudig naar gehard. Kies wat past bij
wie je moet vertrouwen.

| Niveau | Opzet | Geschikt voor | Wie je moet vertrouwen |
|---|---|---|---|
| **A. Eenvoudig** | Eén VPS (vanaf een paar euro per maand), Debian, Nginx of Caddy, MariaDB lokaal | Een kleine groep, weinig onderhoud | De VPS-provider: die heeft fysieke toegang tot de schijf |
| **B. Thuis of op kantoor** | Eigen hardware achter een tunnel (Cloudflare Tunnel, Tailscale Funnel, WireGuard naar een VPS), geen portforward, web en database in aparte containers | Wie de data fysiek in huis wil | De tunnelprovider ziet metadata (wie wanneer verbindt); uptime hangt aan je eigen verbinding |
| **C. Gehard** | Eigen hardware, volledige schijfversleuteling, apart netwerksegment (VLAN), geen logging, eventueel als Tor onion service | Wie ook de hostingkant niet wil vertrouwen | Alleen jezelf. Het JavaScript-punt uit het threat model blijft gelden. |

## Voor elk niveau

**Minimaal.** 1 vCPU, 1 GB geheugen, 10 GB schijf is ruim voor tientallen gebruikers. De
zware rekenstappen (sleutels maken, PBKDF2) gebeuren in de browser, niet op de server.

**Database alleen intern.** MariaDB luistert op `127.0.0.1` of op het interne netwerk tussen
web- en databasecontainer, nooit op `0.0.0.0` richting internet. Geen poort 3306 openzetten.

**Logs uit.** Webserverlogs zijn een van de grootste metadatalekken: ze leggen vast wie op
welk moment welke pagina opvroeg. Zet de toegangslog uit (`access_log off;` in Nginx,
`log { output discard }` in Caddy) of anonimiseer IP-adressen. Foutlogs mogen blijven,
Deaddrop schrijft er geen inhoud in.

**Schijfversleuteling.** Gewiste ciphertext kan nog op de schijf staan, in oude databasepagina's.
Met volledige schijfversleuteling (LUKS) is een gestolen schijf onbruikbaar.

**Back-ups kort.** Een back-up bewaart alles wat burn after read juist weggooit. Maak back-ups
alleen als het moet, versleuteld, en bewaar ze kort. Zet de binlog van MariaDB uit als je geen
replicatie gebruikt (`skip-log-bin`).

**Updates.** `unattended-upgrades` op Debian, en `git pull && php tools/migrate.php` voor
Deaddrop zelf.

**Geen portforward thuis.** Voor niveau B: open geen poorten op je router. Een portforward
koppelt je thuisadres aan de node en zet je hele thuisnetwerk een stap dichter bij internet.
Een tunnel doet hetzelfde werk zonder dat.

## Demo op gedeelde hosting

Een interface-demo kan op gedeelde hosting (Plesk, cPanel), maar alleen met nepdata, zonder
echte gebruikers, en met de vermelding dat het een demo is. De hoster kan bij bestanden,
`.env` en database; daar hoort niets echts in.
