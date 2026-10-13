# Threat model

Deaddrop gaat over vertrouwelijke berichten. Een te grote belofte is hier gevaarlijk:
iemand vertrouwt erop in een situatie waarin misgaan echt gevolgen heeft. Daarom staat
hier precies wat het wel en niet beschermt.

## De verdedigbare claim

> De beheerder van een node kan je berichten niet achteraf uit een databasedump of
> back-up lezen, mits hij nooit de uitgeleverde JavaScript of een public key heeft
> gemanipuleerd, en mits je een sterke private pass koos.

Alles hieronder is een uitwerking van die zin.

## Wie is wie

| Partij | Wat hij kan zien of doen |
|---|---|
| **Netwerk** (wifi, provider) | Versleuteld HTTPS-verkeer. Ziet dat je de node bezoekt, niet wat je doet. |
| **Andere gebruikers** | Alleen channels waar ze lid van zijn, en alleen berichten aan henzelf. |
| **Beheerder (operator)** | Database, bestanden, webserverlogs, de code die naar je browser gaat. |
| **Hostingpartij** | Alles wat de beheerder kan, plus fysieke toegang tot de schijf. |
| **Iemand met een databasedump** | Wat in de database staat, op het moment van de dump. |

## Waartegen het beschermt

**Iemand met de database (dump, back-up, gestolen schijf).** Ziet public keys, ingepakte
private keys en ciphertext. Om een bericht te lezen moet hij een private pass raden: per
poging 600.000 PBKDF2-iteraties. Een lange pass maakt dat onhaalbaar; een korte niet.
Gelezen, verlopen en vergrendelde berichten hebben geen ciphertext meer, en na 24 uur is
ook de rij weg.

**Het netwerk.** Alles gaat over HTTPS, en de inhoud is daarbinnen nog eens end-to-end
versleuteld.

**Andere gebruikers.** De server controleert lidmaatschap en ontvanger bij elke actie.
Een bericht is versleuteld voor één ontvanger; een ander lid kan het niet openen, ook niet
met toegang tot de database.

**Een vervalst bericht van een ander lid.** Ondertekende berichten bevatten afzender,
ontvanger en tijdstip in de handtekening. Een lid kan geen bericht maken dat van iemand
anders lijkt te komen, en een bericht niet doorsturen alsof het voor een ander bedoeld was.

**Een server die stilletjes een sleutel vervangt.** Je browser pint de fingerprint van elk
contact, in de database én in localStorage. Verandert een sleutel, dan blokkeert het
versturen tot je de nieuwe fingerprint buiten Deaddrop hebt gecontroleerd. Een operator die
de pin-tabel leegmaakt, komt niet langs de lokale pin. Dit werkt vanaf het tweede contact:
bij het allereerste contact moet je de fingerprint zelf vergelijken (zie hieronder).

**Wachtwoorden raden op het loginformulier.** Throttling per username en per IP, met
oplopende lockout. Een niet-bestaande username kost even veel tijd als een bestaande, zodat
de responstijd niet verraadt welke accounts er zijn.

**Een gestolen wachtwoord.** Met de trusted-device-gate aan kan iemand met alleen een
wachtwoord niet inloggen vanaf een eigen apparaat. En ook ingelogd heeft hij je private pass
nog nodig om berichten te openen.

## Waartegen het niet beschermt

**1. Een kwaadwillende operator die de JavaScript aanpast.** Dit is de belangrijkste grens,
en hij geldt voor elke webapp die in de browser versleutelt. De node levert de code die je
private pass verwerkt. Eén aangepaste regel in `crypto.js` volstaat om je pass naar de server
te sturen. Subresource Integrity helpt niet: dezelfde partij schrijft de pagina en de hashes.
Signal en vergelijkbare apps omzeilen dit met een ondertekende app; een website kan dat niet.

Wat Deaddrop wel doet: manipulatie *zichtbaar* maken. Elke release heeft een manifest met
hashes, `verify.php` toont wat de node uitlevert, en in de devtools kun je de hash van de
geladen JavaScript zelf nameten. Een gerichte aanval op één gebruiker wordt zo een risico
voor de aanvaller. Zie [verify.md](verify.md).

**2. Het eerste contact.** De eerste keer dat je iemands sleutel ziet, kan de browser niet
weten of hij echt is ("trust on first use"). Vergelijk de fingerprint daarom één keer
persoonlijk of telefonisch, en markeer hem als gecontroleerd.

**3. Metadata bij de operator.** Wie in welk channel zit. Hoeveel berichten er voor iemand
klaarstaan en ongeveer wanneer (op 5 minuten afgerond). Wanneer iemand inlogt (op de dag
afgerond). Wie op welk moment een pagina opvraagt, in de webserverlogs: zet die uit of
anonimiseer ze (zie [reference-architecture.md](reference-architecture.md)). Zonder een
volledig ander ontwerp krijg je dit niet weg.

**4. Forward secrecy.** Elke gebruiker heeft één sleutelpaar. Wie ooit een private key én
oude ciphertext bemachtigt, leest die met terugwerkende kracht. Burn after read en de
cleanup zorgen dat er weinig oude ciphertext bestaat, maar back-ups en binlogs van de
database kunnen hem langer bewaren (punt 6). Een Double Ratchet zoals Signal is een herbouw,
geen aanpassing.

**5. Een zwakke private pass.** De ingepakte private key staat in de database. Iedereen met
leesrechten kan offline een woordenboekaanval doen. 600.000 iteraties vertragen dat; alleen
een lange pass maakt het onhaalbaar.

**6. Back-ups, binlogs en de schijf.** `UPDATE ... SET encrypted_payload = NULL` wist de rij,
maar niet een eerdere back-up, de binlog van de database of oude pagina's op de schijf.
Versleutel de schijf, bewaar back-ups kort, en zet binlogs uit als je ze niet nodig hebt.

**7. Een gecompromitteerd apparaat.** Malware of een browserextensie op het apparaat van
afzender of ontvanger leest gewoon mee. Daar helpt geen enkele versleuteling tegen.

**8. Gedeelde hosting.** Een node op shared hosting deelt server, PHP en database met andere
klanten van de hoster, en de hoster kan bij alles. Gebruik shared hosting alleen voor een
demo met nepdata.

## Bewuste keuzes

- **Ondertekend is de standaard.** Een bericht dat je niet kunt toeschrijven, kun je ook niet
  vertrouwen. "Anoniem" betekent daarom: anoniem voor de server en iedereen behalve de
  ontvanger. Wie echt niet-toeschrijfbaar wil versturen, kiest expliciet "anoniem versturen";
  de ontvanger ziet dan dat het bericht niet te controleren is.
- **Een gecrashte browser is een verloren bericht.** Dat is wat burn after read betekent. Het
  leesvenster van 5 minuten en de 5 pogingen vangen normale vergissingen op.
- **Geen herstel via de server.** Ben je je private pass kwijt, dan kan niemand je berichten
  nog openen, ook de beheerder niet. Maak een back-up van je ingepakte sleutels (pagina
  Sleutels) en bewaar die offline.
- **PBKDF2 in plaats van Argon2id.** Argon2id is sterker tegen gespecialiseerde hardware, maar
  vraagt een WebAssembly-module: meer code om te controleren, en lastig met de strikte CSP.
  Het aantal iteraties staat per gebruiker in de database, zodat een overstap later kan
  zonder iemand buiten te sluiten.
