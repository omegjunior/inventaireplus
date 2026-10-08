-- Audit header for an inventory recreated after a warehouse selection error.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_warehouse_migration
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_inventory_source     integer NOT NULL,
    fk_inventory_target     integer NOT NULL,
    fk_session_source       integer NOT NULL,
    fk_session_target       integer NOT NULL,
    fk_warehouse_source     integer NOT NULL,
    fk_warehouse_target     integer NOT NULL,
    contribution_count      integer DEFAULT 0 NOT NULL,
    datec                   datetime NOT NULL,
    fk_user_author          integer NOT NULL
) ENGINE=innodb;
