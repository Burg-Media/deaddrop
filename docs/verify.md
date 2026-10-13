# Controleren welke code een node uitlevert

Deaddrop versleutelt in je browser, met JavaScript die de node uitlevert. Wie de node
beheert, kan die JavaScript aanpassen. Dat is niet te voorkomen (zie het threat model),
wel na te gaan. Zo doe je dat.

## Wat er bij een release hoort

Elke release is een git-tag op [GitHub](https://github.com/Burg-Media/deaddrop) (bijvoorbeeld
`v0.4.0`) en bevat een `release-manifest.json` met:

- per bestand een SHA-256 (alle PHP, JavaScript, CSS en SQL)
- voor de bestanden in `public/assets/` ook de SRI-hash (`sha384-...`)

Dat manifest in de tag op GitHub is de referentie. Het wordt bij een release gemaakt met:

```bash
php tools/build_manifest.php
```

## Drie manieren om te controleren

### 1. verify.php

Elke node heeft een publieke pagina `/verify.php`. Die toont de versie, de SHA-256 van het
manifest op de node, en per bestand of de live hash gelijk is aan het manifest.

Vergelijk de SHA-256 van het manifest op de node met die van `release-manifest.json` in
de git-tag van die versie op GitHub. Zijn ze gelijk en staat er overal "gelijk", dan draait de node ongewijzigde code.

Let op: een kwaadwillende node kan ook `verify.php` aanpassen. Deze pagina is een snelle
controle, geen bewijs. Daarvoor zijn de volgende twee.

### 2. De JavaScript in je eigen browser

Wat telt, is wat jouw browser krijgt. In Chrome of Firefox:

1. Open een pagina van de node en daarna de devtools (F12).
2. Tab Elements: zoek de `<script>`-tag van `assets/js/crypto.js`. Het
   `integrity`-attribuut moet gelijk zijn aan de `sri` van
   `public/assets/js/crypto.js` in het manifest uit de git-tag.
3. Doe hetzelfde voor `keys.js`, `open-message.js`, `create-message.js`, `key-setup.js`,
   `profile.js`, `ui.js` en `terminal-dashboard.js`.

De browser laadt een script alleen als de inhoud precies bij die hash past. Klopt de hash
in de pagina met het manifest, dan heeft je browser de gepubliceerde code uitgevoerd.

Zelf nameten kan ook:

```bash
curl -s https://drop.example.org/assets/js/crypto.js | openssl dgst -sha384 -binary | openssl base64 -A
```

### 3. Vergelijken met de broncode

```bash
git clone --branch v0.4.0 https://github.com/Burg-Media/deaddrop.git
cd deaddrop
shasum -a 256 public/assets/js/crypto.js
shasum -a 256 release-manifest.json
```

## Wat dit oplost en wat niet

Het maakt een stille, permanente aanpassing van de code zichtbaar voor wie kijkt. Het
voorkomt niet dat een node één keer, aan één gebruiker, andere code geeft. Wie zich daar
tegen wil wapenen, controleert de integrity-attributen elke keer voordat hij zijn private
pass intypt, of draait de node zelf.
