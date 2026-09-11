# Import or export a structured definition

Authorized editors can import a Business Process v2 JSON document through the
web frontend. The document must contain exactly the structured definition
contract used by the API:

```json
{
  "version": 2,
  "metadata": {"Title": "Example"},
  "nodes": [
    {
      "type": "process",
      "name": "example",
      "operator": "&",
      "children": ["host;service"],
      "display": 1
    }
  ],
  "roots": ["example"]
}
```

The module rejects unknown versions, malformed metadata, duplicate or invalid
nodes, invalid roots and unsupported node types before storing anything. The
normal permission, public-health and name-collision checks still apply. An
import cannot overwrite an existing process; edit or delete that definition
explicitly instead.

Exported downloads use the same JSON contract. Historic plaintext process
files are not accepted and are not converted automatically.
