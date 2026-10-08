# CHANGELOG MODULE INVENTAIREPLUS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.3.0 - 2026-10-06

- Add a paginated and filterable second-count entry workflow based on an immutable first-count snapshot.
- Protect verified quantities with atomic saves, optimistic locking and truncated-request detection.
- Generate first-count control and quantity-free second-count sheets together in PDF and XLSX formats.
- Generate separate verified second-count results with quantities, discrepancies and verifier traceability.
- Keep PDF and XLSX line ordering identical by grouping contributions by zone while preserving insertion order within each zone.
- Add an on-demand XLSX counterpart next to generated warehouse valuation PDFs in the mass-files area.
- Preserve four-eyes approval separation while allowing contributors to enter second-count quantities.
- Require a current verified result before non-administrator consolidation.
- Add second-count verification and report storage tables with their database constraints.
- Prevent long warehouse descriptions from overlapping dates in inventory minutes and discrepancy PDFs.
- Recreate an open inventory in the correct warehouse and atomically migrate its collaborative contributions while retaining the abandoned source for audit.

## 1.2.0 - 2026-10-06

- Split collaborative counting and four-eyes control into native Dolibarr tabs.
- Keep users on the appropriate view after contribution, approval and consolidation actions.
- Move control-sheet generation, approval and consolidation actions to the control tab.
- Paginate collaborative totals at database level for large inventories.
- Load totals and recent contributions only on the counting tab.
- Preserve native confirmations, CSRF protection and transactional consolidation rules.
- Improve the counting form spacing without introducing duplicate tab borders.

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
