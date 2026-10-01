# CHANGELOG MODULE INVENTAIREPLUS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.1 - 2026-10-01

- Replace the collaborative count product input with the native Dolibarr product selector.
- Support product lookup by reference, barcode and label while preserving barcode scanner entry.
- Display the inventory warehouse stock instead of the aggregate stock across warehouses.
- Restrict collaborative product search to products belonging to the current inventory.
- Add secured inventory, warehouse, status and permission checks to product autocomplete.

## 1.1.0 - 2026-09-29

- Add four-eyes control workflow for collaborative inventory counts.
- Add printable contribution control sheets grouped by physical zone.
- Add immutable SHA-256 snapshots, reviewer approval and automatic invalidation.
- Require an approved control before consolidation, with an administrator override.
- Add transactional concurrency protection for contribution changes and consolidation.
- Add native Dolibarr PDF header, POST generation and TCPDI compatibility.

## 1.0

Initial version
