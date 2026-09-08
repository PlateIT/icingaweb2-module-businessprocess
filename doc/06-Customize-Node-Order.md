# Customize Node Order

By default all nodes are ordered alphabetically in the UI. Unlock a process to
switch it to manual ordering. Once manual ordering is enabled, it applies to
the complete process definition.

## Reorder by Drag and Drop

In tile view, drag a tile to the desired position. In tree view the same action
can also move nodes across the hierarchy; unfold the target process first.

![Grab Tile](screenshot/06_customize_node_order/0501_tiles_grab_tile.png)
![Drop Tile](screenshot/06_customize_node_order/0502_tiles_drop_at_location.png)
![Grab Row](screenshot/06_customize_node_order/0503_tree_grab_header.png)
![Drop Row](screenshot/06_customize_node_order/0504_tree_drop_at_location.png)

The authoritative version 2 JSON definition stores root and child arrays in
their display order. There is no line-oriented configuration-file syntax or
compatibility header.
