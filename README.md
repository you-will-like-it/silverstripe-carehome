# youwilllikeit/silverstripe-carehome

CareHome Management System za Silverstripe CMS 6 (PHP 8.3+). Modul je predviđen za instalaciju na lokalni server u mreži doma.

Sadrži:

- **Hijerarhiju smještaja**: zgrada/zona → soba → krevet, sa QR kodom na svakom krevetu.
- **Štićenike**: jedan štićenik po krevetu, statusi i odsustvo.
- **Zadatke**: globalni i individualni šabloni, dnevna checklista i dinamički vitalni parametri.
- **Radnički portal** (`/portal`): prijava PIN-om, skeniranje QR koda, primopredaja smjene.
- **Kuhinjski pregled** (`/kuhinja`).
- **Ljekarski PDF izvještaj**.
- **Audit log** svih promjena.

## Instalacija

```bash
composer require youwilllikeit/silverstripe-carehome
vendor/bin/sake db:build --flush
```

`.env` (obavezno, min. 32 znaka; generisati npr. `openssl rand -hex 32`):

```
CAREHOME_PIN_PEPPER="…"
```

Bez ove varijable se PIN-ovi ne mogu postaviti niti koristiti. Ako se pepper promijeni, svi PIN-ovi postaju nevažeći i moraju se ponovo postaviti.

Cron (svaki dan iza ponoći):

```
5 0 * * *  www-data  cd /var/www/carehome && vendor/bin/sake tasks:carehome-generate-tasks
```

Pojedinačni štićenik: `vendor/bin/sake tasks:carehome-generate-tasks --patient=12`.

Cron služi samo da se prozor od 7 dana pomjera naprijed. Sve izmjene šablona, dodjela, statusa i odsustva sinhronizuju se odmah pri snimanju.

**HTTPS je obavezan.** Kamera u pregledniku (`getUserMedia`) radi samo u sigurnom kontekstu. Ako se CMS otvara pod drugim hostom nego što ga koriste telefoni, postavite `QrCodeService.portal_base_url`.

Baza: MySQL/MariaDB (ili PostgreSQL). Evidencija zadataka koristi `SELECT … FOR UPDATE`.

## Demo podaci za prezentaciju

```bash
vendor/bin/sake tasks:carehome-seed-demo                        # 30 dana unaprijed, 14 unazad
vendor/bin/sake tasks:carehome-seed-demo --future=30 --history=14
vendor/bin/sake tasks:carehome-seed-demo --purge                # samo ukloni demo podatke
```

Svako pokretanje prvo briše prethodne demo podatke, pa ih ponovo generiše u odnosu na današnji datum. Briše se samo ono što je seed kreirao (registar `CareHome_DemoSeedRecord`), a stvarni podaci se ne diraju. U `live` okruženju task odbija rad bez `--force`.

Seed kreira:

- 2 zgrade, 3 sprata, 10 soba i 19 kreveta;
- 15 štićenika (jedan privremeno odsutan, jedan otpušten), sa terapijom, dijetetskim oznakama i jednokratnim terminima;
- globalne šablone zadataka i jelovnik za cijeli period;
- historiju: odrađene i preskočene zadatke, vitalne parametre (sa odstupanjima), napomene, izbore obroka i primopredaje smjena;
- zadatke za narednih 30 dana.

Radnici (PIN radi samo ako je `CAREHOME_PIN_PEPPER` postavljen):

| Radnik | Uloga | PIN | Zone |
|---|---|---|---|
| Amra Selimović | Njegovatelj | 2580 | Zgrada A |
| Nermin Hadžić | Njegovatelj | 3691 | Zgrada A i B |
| Senad Mujić | Njegovatelj | 4702 | Zgrada A i B |
| Lejla Đurić | Njegovatelj | 6914 | Zgrada B |
| Jasmina Kurtović | Kuhinja | 5813 | – |
| Demo Administrator | Administrator | 7924 | sve |

Demo radnici nemaju lozinku za CMS. Ako neki od ovih PIN-ova već koristi postojeći korisnik, demo radnik se kreira bez PIN-a, a task to ispiše.

Zadaci iza uobičajenog horizonta od 7 dana ostaju kakvi su generisani. Dnevni cron ih ažurira tek kad uđu u prozor od 7 dana.

## Uloge i prava

`db:build` kreira tri grupe sa pravima. Uloga se postavlja na članu (Sigurnost → član → tab „Dom za njegu“), a grupa se sinhronizuje automatski.

| Uloga | Grupa | Pristup |
|---|---|---|
| Administrator | `carehome-administrators` | CMS sekcije modula, korisnici, PDF, audit log, portal, kuhinja |
| Njegovatelj | `carehome-careworkers` | `/portal` (PIN) |
| Kuhinja | `carehome-kitchen` | `/kuhinja` (PIN) |

Superadmin je član sa core `ADMIN` pravom. Samo on uređuje definicije vitalnih parametara i može brisati medicinske zapise.

**PIN** ima 4–6 cifara i jedinstven je po radniku. Prejednostavni PIN-ovi (1111, 1234 …) se odbijaju. U bazi se čuva samo HMAC-SHA256(pepper, PIN), jer prijava samo PIN-om traži deterministički lookup. Zaštitu čine pepper izvan baze i rate limit: 8 neuspjelih pokušaja po IP-u, zatim 15 minuta blokade.

PIN prijava nikad ne otvara CMS sesiju. Sesija portala ističe nakon 2 h neaktivnosti, a najkasnije nakon 14 h.

**Zone**: radnik vidi štićenike u dodijeljenim zonama i svim njihovim podzonama. Administrator vidi sve.

## Ključna pravila

- **Krevet 1:1.** Jedini izvor istine je `Patient.BedID`. Polje `BedLock` drži ID kreveta samo dok je štićenik aktivan, inače je `NULL`, a nad njim je UNIQUE indeks. Zato ni istovremeni upisi ne mogu dodijeliti isti krevet dvojici štićenika. Zauzetost kreveta se računa, ne čuva se.
- **Status ≠ Aktivan** (Otpušten, Preminuo, Premješten):
  - datum i razlog prestanka su obavezni;
  - krevet se automatski oslobađa;
  - budući otvoreni zadaci se brišu, kao i izbori obroka od sutra.
  - Historija kreveta ostaje u `BedAssignment`.
- **Privremeno odsutan** (checkbox):
  - krevet ostaje rezervisan;
  - ne generišu se zadaci niti izbor obroka;
  - skeniranje kreveta prikazuje samo ime i „Privremeno odsutan“.
- **Sinhronizacija zadataka** obuhvata prozor `[sada, danas + 7]`:
  - kreira, ažurira (snapshot lijeka/doze) i briše samo **otvorene** zadatke u budućnosti;
  - odrađeni, preskočeni i zakašnjeli zadaci se nikad ne diraju.
- **Promjena terapije „od sutra“**: postojećem šablonu postavite `Do datuma` = danas i dodajte novi šablon sa `Od datuma` = sutra. Ako direktno uredite postojeći šablon, promjena važi i za preostale današnje doze.
- **Poništavanje unosa**: radnik može poništiti svoj unos u roku od 30 min (`TaskStatusService.undo_window_minutes`). Nakon toga ispravku radi administrator u CMS-u, i ona se bilježi u audit logu.
- **Obroci**: uvijek se biraju danas za sutra (ručak i večera, bez doručka). Dijetetske oznake su opcionalne. Ako štićenik ima oznaku, ne nude mu se jela označena kao „nije prikladno za“ tu oznaku. Bez oznaka se nude sva jela.

## Audit log

Silverstripe 6 nema ugrađen log promjena podataka:

- `silverstripe/auditor` bilježi samo prijave, objave i promjene prava, i to u syslog;
- Versioned historija pokriva samo verzionisane objekte.

Zato modul ima vlastiti `AuditableExtension`. On bilježi create, update i delete sa vrijednostima prije i poslije, korisnika, kanal (cms/portal/kitchen/cli) i IP adresu. Tome se dodaju PIN prijave i odjave, neuspjeli pokušaji i generisanje PDF-a.

Zapisi se ne mogu uređivati niti brisati. Lozinke, PIN-ovi i tokeni se maskiraju (`AuditLogger.masked_fields`).

Ograničenje: obične `many_many` veze se ne bilježe. To su dijetetske oznake štićenika i oznake „nije prikladno“ na jelovniku. Veze koje utiču na pristup ili zadatke (radnik ↔ zona, štićenik ↔ globalni zadatak) idu preko `through` objekata i bilježe se.

## Konfiguracija (app/_config/carehome.yml)

```yaml
YouWillLikeIT\CareHome\Service\QrCodeService:
  portal_base_url: 'https://dom.local'
YouWillLikeIT\CareHome\Service\MealSelectionService:
  selection_cutoff: '18:00'            # null = bez roka
YouWillLikeIT\CareHome\Service\MedicalReportService:
  facility_name: 'Dom za starije „Primjer"'
  facility_address: 'Ulica 1, 71000 Sarajevo'
YouWillLikeIT\CareHome\Service\TaskGenerator:
  horizon_days: 7
YouWillLikeIT\CareHome\Security\PortalAuthenticator:
  idle_timeout: 7200
YouWillLikeIT\CareHome\Model\Patient\Patient:
  validate_jmbg: true                  # false za DACH
# Dvije smjene umjesto tri (null uklanja podrazumijevanu smjenu):
YouWillLikeIT\CareHome\Service\ShiftResolver:
  shifts:
    Morning: null
    Afternoon: null
    Night: null
    Day: { label: 'Dnevna', start: '07:00', end: '19:00' }
    NightShift: { label: 'Noćna', start: '19:00', end: '07:00' }
```

## PDF

Modul koristi `PdfRendererInterface`. Podrazumijevana implementacija je `MpdfRenderer` (mpdf/mpdf).

Za `kalakotra/silverstripe-pdf` dovoljna je jedna adapter klasa koja implementira interfejs, uz binding:

```yaml
SilverStripe\Core\Injector\Injector:
  YouWillLikeIT\CareHome\Pdf\PdfRendererInterface:
    class: App\Pdf\KalakotraCareHomeRenderer
```

## Frontend (Vite)

Izvor je u `client/src`: Tailwind 4, Alpine.js i `html5-qrcode`, koji se učitava tek na zahtjev. Build je commitovan u `client/dist`, tako da serveru ne treba Node.

```bash
npm install
npm run build          # produkcija
npm run dev            # HMR; uz ViteAssets.dev_server_url: 'https://localhost:5173'
```

## Prevodi

Svi stringovi prolaze kroz `_t()` / `<%t %>`, sa bosanskim tekstom kao izvornim. Lang fajlovi za druge jezike se generišu ovako:

```bash
vendor/bin/sake tasks:i18nTextCollectorTask --module=youwilllikeit/silverstripe-carehome --locale=en
```

Zatim prevedite `lang/en.yml` i postavite locale instalacije.

## Rute

| Ruta | Namjena |
|---|---|
| `/portal` | Prijava i pregled štićenika u zonama radnika |
| `/portal/bed/{hash}` | Cilj QR koda kreveta |
| `/portal/patient/{id}` | Profil, checklista, 7 dana, napomene, obrok |
| `/portal/handover` | Primopredaja smjene (kritične napomene smjene, po zoni) |
| `/kuhinja` | Zbirni pregled (`?range=today\|tomorrow\|week`, `&print=1`) |
| `/carehome-report/form/{id}` | Ljekarski PDF, proizvoljan period (pravo `CAREHOME_REPORTS`) |
| `/carehome-qr/{bed\|room\|zone}/{id}` | Štampa QR oznaka |
