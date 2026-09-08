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

Das Greenfield-Modell besitzt keine manuell vergebenen Public-IDs und keinen
Legacy-Fallback. Konfigurations- und Komponentenpfade werden automatisch aus
Anzeigenamen und der Prozesshierarchie normalisiert. Eine Umbenennung ändert
damit bewusst den URL-Pfad. Die über die API in PostgreSQL gespeicherte
Definition ist die einzige Runtime-Quelle. Exportierte JSON-Definitionen
können zusätzlich in Git geprüft und versioniert, müssen für den Betrieb aber
explizit wieder über die API eingespielt werden.

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

Eine Konfiguration besitzt ausschließlich diese Public-Health-Einstellungen:

```text
PublicApi          no
PublicApiScope     roots
PublicApiRelations none
```

Ein veröffentlichbarer `BpNode` besitzt nur `public_status <node>;yes`. Es gibt
weder `PublicApiId` noch `public_id`. Das Formular zeigt den automatisch
erzeugten Pfad schreibgeschützt an.

Die Veröffentlichung erfordert zwei Opt-ins:

1. `PublicApi = yes` für die Konfiguration.
2. `public_status = yes` für jeden veröffentlichten Prozessknoten.

`PublicApiScope = roots` erlaubt nur Root-Prozesse; `published` erlaubt auch
explizit veröffentlichte verschachtelte Prozesse. Infrastruktur-Blätter und
implizite Kubernetes-Abhängigkeiten sind nicht direkt veröffentlichbar.

Pfade entstehen durch UTF-8-Transliteration, Kleinschreibung und Ersetzung
nicht alphanumerischer Folgen durch `-`. Der Knotenpfad folgt der kürzesten
eindeutigen veröffentlichten Prozesshierarchie. Kollisionen lassen sich nicht
durch IDs übersteuern und machen die Konfiguration ungültig; stattdessen müssen
die Anzeigenamen eindeutig gewählt werden. Ein einzelnes generiertes Segment
ist auf 63 Zeichen begrenzt; längere Anzeigenamen erhalten einen stabilen
Hash-Suffix. Eingehende Pfade müssen exakt dem kanonischen Kleinbuchstaben-
Format entsprechen und sind insgesamt auf 2048 Zeichen begrenzt.

Dieselbe Kollision-/Scope-Prüfung läuft im normalen Editor, beim JSON-Import und
erneut beim anonymen Lesen. Eine ungültige Definition wird nicht teilweise
veröffentlicht. Definitionen und Zustände werden für jede Berechnung erneut aus
den autoritativen Quellen gelesen; der PHP-Prozess hält keinen Public-Health-
Definitionscache.

Eine Antwort verarbeitet höchstens 100 veröffentlichte Konfigurationen und je
Konfiguration höchstens 1000 veröffentlichte Knoten. Der serialisierte Body ist
auf 4 MiB begrenzt. Eine Überschreitung wird vollständig und ohne Teilantwort als
technischer Fehler mit `503` behandelt.

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

- Die API ist ohne globalen Opt-in nicht erreichbar; Parser, Renderer und Modelle
  akzeptieren keine manuellen Public-IDs.
- Responses enthalten ausschließlich `status`, `components` und erlaubte
  `details`.
- Nicht veröffentlichte Objekte sind weder direkt noch über Relationen sichtbar.
- Anonyme und authentifizierte Requests ergeben dieselbe öffentliche Antwort.
- GET und HEAD funktionieren; Schreibmethoden liefern 405.
- Zustandsmapping, Aggregation, Pfadbildung, Kollisionen, `no-store`,
  HTTP-Codes und Exception-Sanitizing sind automatisiert getestet.
- Gemischte klassische und dynamische Kubernetes-Prozesse liefern jederzeit
  korrekt beziehungsweise explizit `UNKNOWN`, aber keine Infrastrukturdetails.
