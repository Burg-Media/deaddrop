# Tests

`e2e.mjs` test Deaddrop end-to-end in een echte Chrome (headless, via het DevTools Protocol),
tegen een verse testdatabase. Hij controleert onder meer:

- sleutels maken in de browser (600.000 iteraties, ondertekensleutel)
- een ondertekend bericht: geen platte tekst in de database, afgeronde tijdstempel, geen afzenderkolom
- burn after read: status `reading` bij de eerste weergave, ciphertext gewist na lezen,
  herladen toont niets, 5 foute passes vergrendelt en wist
- een anoniem bericht
- een operator die de public key van een ontvanger vervangt: versturen wordt geblokkeerd,
  ook nadat de pin-tabel in de database is leeggemaakt
- login-throttling, met alleen HMACs in de database
- het upgraden van oude sleutels (250.000 iteraties, zonder ondertekensleutel)
- private pass wijzigen met gelijke fingerprint
- de cleanup-cron
- geen CSP-schendingen of JavaScript-fouten

```bash
PHP=/pad/naar/php MYSQL="mysql -uroot -p<wachtwoord>" bash tests/run.sh
```

`run.sh` maakt (en wist!) de database `deaddrop_test`, draait de migraties, zet testgebruikers
klaar (`seed.php`) en start de ingebouwde PHP-server op poort 8766. `seed.php` en `db.php`
weigeren te draaien tegen een database zonder "test" in de naam.

Nodig: PHP 8.1+, MySQL of MariaDB, Node.js 22+ en Google Chrome.
