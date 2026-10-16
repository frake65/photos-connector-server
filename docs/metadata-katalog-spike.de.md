# Machbarkeits-Spike: Metadatenkatalog

Dieser optionale, experimentelle serverseitige Lauf katalogisiert Metadaten unterhalb eines vom angemeldeten Nextcloud-Benutzer ausdrücklich ausgewählten Ordners. Gespeichert werden nur sichtbare Datei-ID, MIME-Typ, Byte-Größe, Änderungszeit und ETag. Der Lauf öffnet keine Dateiinhalte, berechnet keine Hashes, ändert keine Connector-Asset-Zuordnungen und verändert keine Dateien.

## Machbarkeit der Enumeration

Die öffentliche Nextcloud-35-API `OCP\Files\Folder::search(OCP\Files\Search\ISearchQuery)` akzeptiert ein Limit und einen Offset. Der Spike fordert höchstens 100 Cache-Zeilen pro Seite an und sortiert stabil aufsteigend nach `fileid`. Die Suche nach allen Namen ist an den ausgewählten Ordner des Benutzers gebunden, weil `search()` auf dessen `Folder` aufgerufen wird. Es gibt keine direkten SQL-Abfragen, Zugriffe auf den internen Filecache oder Serverpfade. So muss die App einen großen flachen Ordner nicht vollständig im Speicher materialisieren. Nextcloud begrenzt zunächst die Suchzeilen und wandelt sie dann in sichtbare Nodes um. Wird dasselbe Element über mehrere Mount-Aliase eingebunden, kann eine Zeile mehrere Node-Objekte erzeugen. Der Worker fasst diese anhand der Datei-ID zusammen und rückt den Cursor um das Abfragelimit weiter. Der Speicherbedarf ist daher durch Seitengröße mal Anzahl sichtbarer Aliase begrenzt, aber nicht strikt auf 100 Nodes. Bei einem normalen flachen Ordner ohne Aliase enthält die Antwort höchstens 100 Nodes. Datenbankkosten und Speicher müssen trotzdem mit wachsenden Beständen auf einer echten Nextcloud-/PostgreSQL-Installation gemessen werden.

Die eigenständige Test-Fixture mit 1.250 Einträgen bestätigt höchstens 100 angeforderte Cache-Zeilen je Aufruf und 13 Seiten (ein Mount ohne Aliase). Sie belegt das Verhalten der Fixture, nicht Laufzeit oder Speicherverbrauch von Nextcloud, PostgreSQL oder eines Produktivsystems. Offset-Seiten können bei hohen Offsets langsamer werden. Die öffentliche API liefert diesem Job keinen Snapshot-Cursor. Eine geänderte Ordner-ETag startet die Enumeration daher bei Offset null neu und entfernt die Einträge des überholten Durchlaufs für diesen Scan. Eine Datei, deren Metadaten sich während ihrer Seite ändern, wird ausgelassen und markiert den abgeschlossenen Lauf als unvollständig. Zu viele wiederholte Ordneränderungen führen ebenfalls zu einem unvollständigen Ergebnis. Ordner-ETags dienen als Änderungsindikator und garantieren keinen transaktionalen Snapshot.

## Persistenz und Isolation

Der Worker löst die lesbare Ordner-ID des ausgewählten Benutzers für jeden Batch erneut auf. Jobstatus und Katalogeinträge sind nach Nextcloud-Benutzer isoliert. Ein eindeutiger aktiver Bereichsschlüssel verhindert mehrere aktive Jobs für denselben Benutzer und Ordner; Datenbank-Lease und Compare-and-Set erlauben nur einem Worker, einen Job gleichzeitig zu beanspruchen. Eine Metadatenseite wird zusammen mit Offset und Zählern in einer Transaktion gespeichert. Ein Prozessabbruch vor dem Commit wiederholt daher dieselbe Seite. Eindeutige Schlüssel für Bereich/Datei und Lauf/Datei machen die Wiederholung idempotent. Die Wiederherstellung reiht wartende Jobs und abgelaufene Leases erneut ein.

Der Lauf speichert die stabile Ordner-ID und keinen Pfad. Berücksichtigt wird nur eine explizite Liste gängiger Bild- und Video-MIME-Typen. Unbekannte und nicht unterstützte Formate werden übersprungen. Zugriffs- oder Metadatenfehler markieren den Lauf als unvollständig; eine fehlende oder veränderte Beobachtung wird nicht als erfolgreicher Metadateneintrag ausgegeben.

## Experimentelle API

Die Routen verlangen die bestehende Connector-Authentisierung mit Basic Authorization und App-Passwort. Der Start benötigt JSON.

| Methode | Pfad | Body / Ergebnis |
| --- | --- | --- |
| `POST` | `/apps/apple_photos_connector/api/v1/catalog/scans` | `{"folderId": 123}`; gibt einen aktiven Lauf zurück oder reiht einen neuen ein. |
| `GET` | `/apps/apple_photos_connector/api/v1/catalog/scans/{scanId}` | Eigentümergebundener Status, Cursor und Zähler. |

Der Worker verarbeitet standardmäßig 50 Nodes pro Seite; der Service begrenzt auf höchstens 100. Die API bietet keinen Parameter für die Seitengröße. `nodesVisited` zählt Dateien und Ordner, die Nextcloud zurückgibt. `filesCatalogued` zählt eindeutige unterstützte Datei-IDs dieses Laufs. Laufzeitwerte gelten je Seite. `processPeakMemoryBytes` ist der PHP-weite Höchststand des Prozesses und kein Höchstwert einer einzelnen Seite; die API kann den Speicher nicht einer Seite zuordnen.

## Testbelege und Grenzen

`php tests/run.php` prüft verschachtelte Ordner, einen synthetischen flachen Ordner mit 1.250 Einträgen, Seitengrenzen, Fortsetzung nach einem Worker-Objekt-Neustart, doppelte Starts, Trennung zwischen Benutzern, unlesbare Ordner, Änderungen während der Beobachtung und Neustart bei geänderter Ordner-ETag. Die Test-Fixture simuliert eine begrenzte Antwort der öffentlichen `Folder::search()`-API. Sie führt Nextclouds echte Abfrageplanung oder PostgreSQL nicht aus. Live-API-, Worker- und Datenbankprüfungen können ergänzt werden, sobald der separat gepflegte Nextcloud-35-/PostgreSQL-Harness auf dem Integrationsbranch verfügbar ist.

Der Spike ist noch nicht produktionsreif. Vor einem Deployment sind Integrationstests mit Nextcloud 35 und PostgreSQL erforderlich: Laufzeit und Speicher bei wachsenden Beständen einschließlich eines sehr großen flachen Ordners messen, API-Verhalten auf der minimal unterstützten Nextcloud-Version bestätigen, Jobunterbrechung und Wiederherstellung mit der realen Queue testen und eine Lebenszyklusregel für veraltete Katalogeinträge festlegen. Dieser Spike löscht keine Zeilen automatisch und startet keine Scans ohne ausdrückliche API-Anfrage.
