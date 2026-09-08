# Public Health API

## Zielbild

Die Public Health API veröffentlicht ausgewählte Business-Prozess-Zustände als
kleine, an Spring Boot Actuator angelehnte JSON-API. Sie ist anonym, nur lesbar
und standardmäßig vollständig deaktiviert. Der öffentliche Vertrag besteht aus
den Strukturelementen `status`, `components` und `details`.

Die Routen werden erst durch den globalen Schalter
`ICINGA_BUSINESSPROCESS_PUBLIC_HEALTH_ENABLED=true` freigegeben. Alternativ kann
außerhalb des Charts in der Modulkonfiguration unter `[general]` die Einstellung
`public_health_enabled = yes` gesetzt werden. Ohne diesen ersten Opt-in antworten
alle Health-Routen einheitlich mit `404`; Definitionen werden dabei nicht geladen.
Im Helm-Chart entspricht dies `businessProcess.publicHealth.enabled: true`.

Der Konfigurationspfad wird als `PublicApiPath` in der Definition gespeichert.
Beim Anlegen wird er aus der ID vorbelegt und kann angepasst werden. Änderungen
am Anzeigenamen ändern diesen gespeicherten Pfad nicht. Bei bestehenden
Definitionen ohne gespeicherten Pfad wird der bisher aus dem Anzeigenamen
generierte Pfad beim Laden übernommen und beim nächsten Speichern persistiert.
Die über die API in PostgreSQL gespeicherte Definition ist die Runtime-Quelle.

## Endpunkte

```text
GET|HEAD /health
GET|HEAD /businessprocess/health
GET|HEAD /businessprocess/health/<configuration>
GET|HEAD /businessprocess/health/<configuration>/<component-path>
```

`/health` beschreibt nur die technische Verfügbarkeit der Health API. Ein
fachlich ausgefallener Prozess macht diesen Discovery-Endpunkt nicht selbst
unhealthy. `/businessprocess/health` aggregiert alle veröffentlichten
Konfigurationen. Die beiden Detailformen liefern eine Konfiguration oder eine
Komponente. Andere Methoden antworten mit `405` und `Allow: GET, HEAD`.

Private, deaktivierte und unbekannte Pfade sind nach außen nicht
unterscheidbar und liefern `404`.

## Actuator-artiges Antwortmodell

Discovery:

```json
{
  "status": "UP",
  "components": {
    "businessprocess": {
      "status": "UP",
      "details": {"href": "/businessprocess/health"}
    }
  }
}
```

Aggregierter Katalog:

```json
{
  "status": "DEGRADED",
  "components": {
    "eiam-produktion": {
      "status": "DEGRADED",
      "components": {
        "portal": {
          "status": "UP",
          "details": {
            "name": "Portal",
            "path": "eiam-produktion/portal",
            "observedAt": "2026-09-01T12:00:00+02:00",
            "links": {"self": "/businessprocess/health/eiam-produktion/portal"}
          }
        }
      },
      "details": {
        "name": "eIAM Produktion",
        "path": "eiam-produktion",
        "observedAt": "2026-09-01T12:00:00+02:00",
        "href": "/businessprocess/health/eiam-produktion"
      }
    }
  }
}
```

`details` enthalten ausschließlich öffentlichen Namen, generierten Pfad,
Beobachtungszeitpunkt und optional Links zwischen ebenfalls veröffentlichten
direkten Komponenten. Hosts, Services, Kubernetes-Objekte, interne IDs, UUIDs,
Selektoren, Restriktionen, interne Verbindungsdaten und Fehlertexte werden nie serialisiert.

## Status und HTTP-Semantik

| Interner Zustand | Public Health |
| --- | --- |
| OK / UP | `UP` |
| WARNING | `DEGRADED` |
| CRITICAL / DOWN | `DOWN` |
| UNKNOWN / PENDING / MISSING / EMPTY | `UNKNOWN` |

Die Aggregation verwendet den schlechtesten Zustand. `UP` und `DEGRADED`
liefern HTTP `200`; `DOWN`, `UNKNOWN`, ein leerer Katalog und technische
Berechnungsfehler liefern `503`. Der JSON-Body bleibt bei `503` erhalten.
`observedAt` bezeichnet den Berechnungszeitpunkt und nicht den letzten
Statuswechsel.

## Veröffentlichung und Pfadbildung

Eine Konfiguration besitzt folgende Einstellungen:

```text
PublicApi          no
PublicApiPath      example-service
PublicApiScope     roots
PublicApiRelations none
```

`PublicApiPath` ist ein einzelnes Segment aus 1 bis 63 Kleinbuchstaben, Ziffern
und einzelnen Bindestrichen zwischen Wörtern. Es ergibt die URL
`/businessprocess/health/<PublicApiPath>`. Der Pfad wird im Formular vorbelegt,
kann bewusst geändert werden und bleibt bei einer Umbenennung des Prozesses
stabil. Das Ändern des Pfades ändert die URL; es gibt keine automatischen Aliase.

Zusätzlich zum globalen Schalter erfordert die Veröffentlichung zwei Opt-ins:

1. `PublicApi = yes` für die Konfiguration.
2. `public_status = yes` für jeden veröffentlichten Prozessknoten.

Das Aktivieren einer neuen, leeren Konfiguration veröffentlicht noch keinen
Knoten. Ein leerer öffentlicher Komponentenbestand ergibt `UNKNOWN`/HTTP 503.
Allowed Users, Groups und Roles gelten für den angemeldeten Webzugriff und
schränken die ausdrücklich anonyme Public Health API nicht ein.

`roots` erlaubt nur ausdrücklich veröffentlichte Root-Prozesse; `published`
erlaubt auch ausdrücklich veröffentlichte verschachtelte Prozessknoten.
Infrastruktur-Blätter und implizite Kubernetes-Abhängigkeiten sind nicht direkt
veröffentlichbar. Beim Deaktivieren der API dürfen Relationseinstellungen für
eine spätere erneute Aktivierung gespeichert bleiben.

Knotenpfade werden aus den Anzeigenamen der veröffentlichten Knoten entlang
der Prozesshierarchie gebildet. Nicht veröffentlichte Elternnamen werden
übersprungen und dürfen nicht in öffentlichen Pfaden erscheinen. Knotenpfade
können sich bei Änderungen veröffentlichter Knotennamen oder ihrer Hierarchie
weiterhin ändern. Der Konfigurationspfad bleibt davon unabhängig stabil.

Kollisionen zwischen veröffentlichten Konfigurationspfaden werden beim
Speichern geprüft. Knotenpfad-, Scope- und Einstellungsprüfungen gelten auch
beim JSON-Import, Export und anonymen Lesen. Ungültige öffentliche Definitionen
werden nicht teilweise veröffentlicht. Beim anonymen Lesen führen auch
Konfigurationspfad-Kollisionen zu einer bereinigten Fehlerantwort.

Eine Antwort verarbeitet höchstens 100 veröffentlichte Konfigurationen und je
Konfiguration höchstens 1000 veröffentlichte Knoten. Der serialisierte Body ist
auf 4 MiB begrenzt. Überschreitungen führen zu HTTP 503 ohne Teilantwort.

## Sicherheitsgrenze

Der Controller ist von den authentifizierten Tree-/Editor-Controllern getrennt.
Er lädt ausschließlich gespeicherte Definitionen, ignoriert Sessions,
Simulationen und ungespeicherte Änderungen. Interne Zustände werden über die
serverseitig authentifizierten Icinga-DB- und Kubernetes-API-Verbindungen
gelesen und sofort auf die öffentliche Allowlist projiziert. Ein Endbenutzer-
oder Browser-Token wird dabei weder benötigt noch weitergereicht.

Antworten verwenden:

```http
Content-Type: application/health+json; charset=utf-8
Cache-Control: no-store
X-Content-Type-Options: nosniff
```

Health-Antworten werden weder in APCu noch durch Browser oder Reverse Proxy
zwischengespeichert, weil aktive Prüfungen stets den aktuellen Business-Status
erhalten müssen. Ingress begrenzt bei Bedarf die Request-Rate. CORS bleibt standardmäßig aus.
Fehlerdetails erscheinen nur in geschützten Serverlogs, niemals in Antworten.

## Abnahmekriterien

- Die API ist ohne globalen Opt-in nicht erreichbar; Controller und Datenzugriff
  laden dabei keine Prozessdefinitionen.
- Responses enthalten ausschließlich `status`, `components` und erlaubte
  `details`.
- Nicht veröffentlichte Objekte sind weder direkt noch über Relationen sichtbar.
- Anonyme und authentifizierte Requests ergeben dieselbe öffentliche Antwort.
- GET und HEAD funktionieren; Schreibmethoden liefern 405.
- Zustandsmapping, Aggregation, Pfadbildung, Kollisionen, `no-store`,
  HTTP-Codes und Exception-Sanitizing sind automatisiert getestet.
- Gemischte klassische und dynamische Kubernetes-Prozesse liefern jederzeit
  korrekt beziehungsweise explizit `UNKNOWN`, aber keine Infrastrukturdetails.
