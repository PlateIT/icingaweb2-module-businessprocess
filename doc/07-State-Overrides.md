# State Overrides

Business processes calculate their state from their children. In addition to
[operators](09-Operators.md), a monitored node can map an observed state to a
different state before aggregation.

## Configure overrides

Unlock the process and edit the node. For every required mapping select the
observed state and its replacement. The example below maps `CRITICAL` to
`WARNING`.

![Service State Override Configuration](screenshot/07_state_overrides/0701_override_config.png "Service State Override Configuration")

Tile view marks an override with an additional state ball showing the original
state. Tree view shows both the original and effective state.

![Overridden Tile State](screenshot/07_state_overrides/0702_overridden_tile.png "Overridden Tile State")
![Overridden Tree State](screenshot/07_state_overrides/0703_overridden_tree.png "Overridden Tree State")

Overrides are stored explicitly in the node's `stateOverrides` object inside
the version 2 JSON definition. The API validates and persists this structure
atomically in PostgreSQL; no file-format extension is involved.
