# Store a configuration

Edits are collected as pending changes until an authorized user selects
**Store**. Dismissing the changes restores the last definition returned by the
API.

Storing encodes the complete process as a versioned structured JSON definition
and sends it to the central Icinga Kubernetes API. The API validates the
contract and stores it atomically as PostgreSQL `jsonb`, incrementing its
generation. No process file or shared filesystem is involved, so every Icinga
Web replica immediately uses the same authoritative definition.

Every update and deletion carries the generation read by that editor. The API
rejects a stale generation with HTTP `409` instead of silently overwriting a
newer change made through another HA Web replica. The editor must then reload
the current definition and deliberately reapply its change.

The **Definition** view shows the pending JSON document. **Diff** compares it
with the current API generation. Downloads use the same JSON representation and
can be reviewed or kept in Git without introducing another runtime source of
truth.

The historic line-oriented `.conf`/`.bp` format is deliberately unsupported.
There is no automatic conversion or fallback in this Greenfield version.
