-- Immutable relation between each original contribution and its migrated copy.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_warehouse_migration_line
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_migration            integer NOT NULL,
    fk_contribution_source  integer NOT NULL,
    fk_contribution_target  integer NOT NULL
) ENGINE=innodb;
