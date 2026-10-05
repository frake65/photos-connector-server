# Photos Connector — Server App

The server-side source, release preparation and issue tracker are maintained in
the [Photos Connector server repository](https://github.com/frake65/photos-connector-server).

App-ID: `apple_photos_connector`, Version 0.9.0 laut `appinfo/info.xml`. PHP ab 8.2; die App-Metadaten deklarieren Nextcloud 34–35. Die separate macOS-App **Photos Connector** ist erforderlich und wird nicht über den Nextcloud App Store verteilt. Original-Uploads, Inventory-v2 mit serverseitiger Idempotenz und additive Album-Synchronisation sind implementiert. Für 0.9.0 werden keine neuen E2E- oder Performance-Erfolge behauptet; der kontrollierte 100-Asset-Test bleibt wegen `InventoryCore.UploadError code=8` vor nachweisbarem Inventory-Transport offen.

## Frische Serverinstallation

Voraussetzung für die beschriebenen Tests ist eine frische Nextcloud-Testinstanz ohne APC-Daten. Die additive Content-Identity-Migration unterstützt bestehende Installationen ab dem APC-Basisschema, übernimmt dabei aber ausschließlich bestätigte APC-Uploads; sie hasht keine Altdateien und ändert kein Inventar-Matching. Dies beschreibt den Test-Ausgangszustand; es ist keine Anleitung zum Löschen vorhandener Nextcloud-Dateien.

Den Inhalt dieses Verzeichnisses unter `<nextcloud>/custom_apps/apple_photos_connector/` ablegen (der installierte Ordner muss der App-ID entsprechen), anschließend als Nextcloud-Webserverbenutzer ausführen:

```sh
php occ app:enable apple_photos_connector
php occ app:list
```

Bei der Installation legt Nextcloud die Connector-Tabellen und Indizes mit dem konfigurierten Tabellenpräfix an. Source-Registrierung, Inventar, Uploads und Album-Zuordnungen beginnen leer. Die App benötigt keine Composer-Pakete im Produktivbetrieb; Nextcloud übernimmt das Autoloading des Namespace `OCA\ApplePhotosConnector`.

## Endpunkte

`new` bedeutet „ohne bestätigten Upload“. Das Inventar liefert dafür Upload-Aufträge; bestätigte Dateien werden als `known` erkannt. Den aktuellen Client mit der frisch installierten Server-App verwenden. Details: [Upload-Protokoll](protocol/uploads.md).

Die Statusantwort weist `protocols.inventory: 2` aus. Kompatible Clients senden
Inventare an `POST /index.php/apps/apple_photos_connector/api/v2/inventory`
mit einem stabilen UUID-`Idempotency-Key`. Ein identischer Request mit
demselben Schlüssel gibt das gespeicherte Ergebnis zurück; ein anderer Inhalt
unter demselben Schlüssel wird abgewiesen. Der bestehende
`POST /index.php/apps/apple_photos_connector/api/v1/inventory` bleibt für
ältere Clients verfügbar.

Request, Antwort, Authentifizierung, Run-Lebenszyklus und Identitätsregeln stehen in [protocol/README.md](protocol/README.md); dort liegen die JSON-Schemas für Request, Erfolg und Controller-Fehler.

```sh
curl --user 'NEXTCLOUD_USER' \
  --header 'Content-Type: application/json' \
  --data-binary @inventory.json \
  'https://cloud.example/index.php/apps/apple_photos_connector/api/v2/inventory'
```

Curl fragt das App-Passwort interaktiv ab. Sources und Assets werden durch den authentifizierten Benutzer isoliert. Der Source-Name kann bei weiteren Läufen aktualisiert werden; die Source-UUID bleibt die logische Identität.

## Aufbau

- `lib/Migration`: persistente Tabellen und Indizes; Source-UUID pro Benutzer eindeutig.
- `lib/Db/InventoryRepository`: Nextcloud-QueryBuilder und Transaktionen.
- `lib/Db/ContentIdentityRepository`: benutzergebundener Index bestätigter SHA-256-/Bytegrößen-Nachweise; nicht in `new`/`known` eingebunden.
- `lib/Service/AssetIdentity`: bevorzugte Cloud-Identität und vorläufiger lokaler Fallback.
- `lib/Db/ImportRun`: zufällige Run-UUID und initialer Audit-Datensatz.
- `lib/Service/InventoryValidator`: Validierung vor Source-/Asset-Änderungen.
- `lib/Service/InventoryService`: Registrierung, `new`/`known`-Verarbeitung und transaktionaler Run-Abschluss.
- `lib/Controller`: authentifizierter JSON-Endpoint.
- `lib/Service/UploadService`: benutzergebundene Upload-Bestätigung und atomare Zähler.
- `lib/Service/UploadedFileLocator`: tatsächliche Datei-ID aus dem Benutzerdateisystem.

Für den Prototyp wird das bestehende Inventar einer Source einmal pro Lauf gelesen und im Speicher indiziert. SHA-256 verifiziert reservierte Uploads und wird nach erfolgreicher Bestätigung zusätzlich als benutzerspezifische Content Identity gespeichert. Diese additive Tabelle wird noch nicht für Inventar- oder `new`/`known`-Matching verwendet. Gleiche Bytes führen weder zum Zusammenführen von Assets noch zu Löschungen.

Upload-Recovery verwendet dauerhaft gespeicherte Ziele. `POST /api/v1/uploads/prepare` reserviert bzw. prüft den Zielpfad anhand Byteanzahl und SHA-256. Derselbe Asset-Datensatz behält seinen Pfad über Runs und Client-Neustarts. Bestehende fremde Dateien werden übersprungen; unklare Prüfergebnisse führen zum Abbruch statt zu einem weiteren Dateinamen. Die Bestätigung prüft die tatsächlichen Bytes erneut. Details und Schemas im [Upload-Protokoll](protocol/uploads.md).

Beim Erzeugen eines Deployment-Archivs auf macOS müssen AppleDouble-Metadateien deaktiviert werden. Andernfalls können Dateien wie `._InventoryController.php` in die Nextcloud-App gelangen und als PHP-Klassen interpretiert werden. Beispiel:

```sh
sh build-package.sh
```

## Tests und statische Prüfungen

Ohne Nextcloud, mit PHP 8.2+ und PDO SQLite:

```sh
php tests/lint.php
php tests/run.php
```

Alternativ `composer lint` und `composer test`. Die Tests führen die echte Migration, das Repository und die Services gegen eine SQLite-In-Memory-Datenbank aus. `SQLiteHarness.php` bildet dafür nur die verwendete OCP-Schnittstelle nach. Dies prüft das Verhalten, ersetzt aber keinen Nextcloud-Integrationslauf und keinen Test der Datenbank-Sperren unter MySQL/PostgreSQL.

In einer separaten, installierten Nextcloud-Testinstanz mit aktivierter App:

```sh
NEXTCLOUD_ROOT=/path/to/nextcloud APC_TEST_DATABASE=disposable php tests/nextcloud.php
```

Dieser Lauf verwendet Nextclouds echten QueryBuilder und dieselben Inventarszenarien. Er erzeugt zufällig benannte Testbenutzer-Namespaces in den App-Tabellen; die Zeilen bleiben absichtlich in der wegwerfbaren Testdatenbank. Keine produktive Instanz verwenden.

## Paketierung / Packaging

Run `sh build-package.sh` from the repository root. The default
output is `.build/server/apple_photos_connector-0.9.0.tar.gz`, containing exactly
one `apple_photos_connector/` directory. The script stages only runtime folders,
README, composer metadata, LICENSE and CHANGELOG; tests and tooling are excluded.
It strips macOS archive metadata. No signing or upload occurs.

`sh check-package.sh STAGING_PARENT [INFO_XSD]` validates the entire
staging parent. PHP DOM/libxml is required (no Composer dependencies). Set
`INFO_XSD=/path/to/info.xsd` when building to validate against the official schema.

Die zwei OCC-Testcommands sind nicht mehr öffentlich registriert. Ihre Klassen
bleiben für Entwicklung und Tests erhalten. Administratoren können weiterhin
`apple-photos-connector:album:sync` verwenden.

The package is licensed under AGPL-3.0-or-later; LICENSE contains the unmodified
GNU AGPL version 3 text. The app icon is provided as `img/app.svg`.

Run-Tests prüfen neue, bekannte und leere Inventare, separate Runs, Benutzertrennung sowie Rollback bei Fehlern während des Abschlusses. Weitere Fehlerproben decken fehlgeschlagene Run-Anlage, ausgefallene Fehlerprotokollierung und verlorene Commit-Bestätigung ab. Die dabei absichtlich ausgelöste Meldung `failed to finalize import run` ist erwartete Testausgabe.

Upload-Tests prüfen Aufträge, Retry nach Fehler, idempotente Bestätigung, Benutzer-/Pfadgrenzen und erhaltene Dateien bei leeren Folgescans. Der Datei-Locator ist dabei ein Testdouble; echte WebDAV-/Nextcloud-Dateisystemintegration ist nicht Teil dieses lokalen Tests. Die Identitätstests verwenden zwischen Scans bestätigte Dateireferenzen als Fixtures, damit sie weiterhin die unveränderte Apple-Identitätslogik prüfen.

Aktueller Prüfstand für Version 0.9.0: Die PHP-Syntaxprüfung für 59 Dateien,
die eigenständigen SQLite-Tests sowie die Paket- und Laufzeitprüfungen sind
bestanden. Die Metadaten wurden gegen die offizielle Nextcloud-`info.xsd`
geprüft. Die Migration und Integration wurden auf der entbehrlichen
Nextcloud-35-Testinstanz geprüft; parallele PostgreSQL-Idempotenz und die
Inventory-v2-HTTP-Wiederholung wurden ebenfalls geprüft. Im macOS-E2E-Test
wurden Upload, Albumabgleich und eine Wiederholung ohne neue Upload-Tickets
bestätigt. Die deklarierte Nextcloud-Spanne 34–35 ist weiterhin keine
vollständig getestete Versionsmatrix; andere Zielsysteme müssen separat
geprüft werden.

## Release 0.9.0 verification

The 0.9.0 release candidate is prepared from the `0.9.0` metadata in
`appinfo/info.xml`. Before signing or publishing, run the complete local
verification from this repository:

```sh
php tests/lint.php
php tests/run.php
sh build-package.sh
php tests/package.php <extracted-staging-parent>
php tests/package-runtime.php <extracted-staging-parent>/apple_photos_connector
git diff --check
```

The package must contain exactly one `apple_photos_connector/` directory and
must not contain tests, development tools, build products, AppleDouble files,
or private keys. The unsigned package intentionally has no
`appinfo/signature.json`; that file is added only by the official Nextcloud
signing step using the issued app certificate.


## API und Alben

Alle Pfade liegen unter `/index.php/apps/apple_photos_connector/api/v1` und erfordern HTTP Basic Authentication mit Nextcloud-Benutzer und App-Passwort. POST-Aufrufe als JSON senden.

| Methode | Pfad | Aufgabe |
| --- | --- | --- |
| GET | `/status` | Verbindung und Anmeldung prüfen |
| POST | `/inventory` | Source und Assets inventarisieren, Upload-Aufträge erhalten |
| POST | `/uploads/prepare` | Ziel reservieren und vorhandenen Inhalt prüfen |
| POST | `/uploads/complete` | Upload bestätigen oder Fehler melden |
| POST | `/albums/inventory` | Album-Metadaten und Mitgliedschaften speichern |
| POST | `/albums/sync` | Gespeicherte Alben mit Nextcloud Photos abgleichen |

Die Source muss vor dem Album-Inventar registriert sein. Der Abgleich nutzt die Photos-Mapper über `NextcloudAlbumAdapter`. Dieser akzeptiert Photos 7.0.0 und 8.0.0 und prüft die benötigten Mapper-Methoden; andere Versionen werden abgewiesen. Ordner werden übersprungen. Mitgliedschaften werden nur für importierte Assets ergänzt. Namen sind keine Schlüssel. Details und Antwortfelder stehen im [Protokoll](protocol/README.md).

## Aktuelles Datenmodell und Fresh-Install-Grenze

Das Basisschema wird durch die Fresh-Install-Migration angelegt. Die additive Content-Identity-Migration ergänzt zwei Tabellen und übernimmt ausschließlich SHA-256-/Bytegrößen-Werte von APC-Upload-Targets, die über ein `uploaded`-Ticket bestätigt wurden. Der Backfill liest keine Nextcloud-Dateien erneut. Reservierungen, fehlgeschlagene und nicht bestätigte Ziele werden ausgelassen.

Das Datenmodell umfasst Sources, Assets, Import-Runs, Upload-Aufträge, mehrere historisierte Upload-Ziele pro Asset, Album-Inventar, Mitgliedschaften, Photos-Zuordnungen und den benutzerspezifischen Content-Index samt Target-Belegen. `current_upload_target_id` ist die alleinige aktuelle Dateizuordnung. Ein Retargeting darf nur bei serverseitig erlaubtem Wiederherstellungsfall erfolgen; das alte Ziel bleibt als Historie erhalten. `retarget_allowed` und `base_target_id` werden ausschließlich aus dem Serverzustand gesetzt, nicht aus Clientdaten. Content-Zeilen sind keine Asset-Zeilen und verändern deren Identitäten nicht.

## Phase 2/3 content identity details

The additive migration is `Version008600Date20260918000000.php`; there is no
`Version008700` migration in this release. It creates `apc_content_identities`
and `apc_content_targets`. The content index is unique by
`user_id + sha256 + byte_size` and remains separate from `new`/`known` asset
matching. The server app version is 0.9.0.

`uploads/prepare` may return `contentAlreadyPresent` for a confirmed target.
The client then performs no PUT and no Complete, but can still run album sync.
This does not alter asset identities or merge equal-byte assets. Only confirmed
APC upload targets are backfilled; reserved, failed, incomplete, orphaned, and
deleted targets are excluded, and files are not re-hashed.

## Album semantics

`/albums/inventory` receives source-scoped album metadata and memberships,
including empty albums and assets belonging to several albums.
`/albums/sync` operates only on albums selected by the client. It restores every
membership whose asset is imported, skips non-imported members, performs no
transitive selection, and is idempotent. Known assets and
`contentAlreadyPresent` results can recover memberships without a new file
upload. The server never deletes an album membership merely because a source
omitted it.

## Packaging safety

`build-package.sh` sets `COPYFILE_DISABLE=1`, excludes AppleDouble metadata, and
fails validation if an archive contains `._*` or `.DS_Store`. This is required
because an earlier `._AlbumController.php` was interpreted as PHP source by
Nextcloud. The package contains runtime code only; tests and build artifacts are
excluded.
