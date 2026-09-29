-- Immutable scan entries. Corrections void an entry instead of deleting it.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_count_contribution
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_session              integer NOT NULL,
    fk_inventory            integer NOT NULL,
    fk_inventorydet         integer NOT NULL,
    fk_warehouse            integer NOT NULL,
    fk_product              integer NOT NULL,
    batch                   varchar(128) NULL,
    zone                    varchar(128) NOT NULL,
    qty                     double(24,8) NOT NULL,
    scan_key                varchar(64) NOT NULL,
    active                  smallint DEFAULT 1 NOT NULL,
    datec                   datetime NOT NULL,
    fk_user_author          integer NOT NULL,
    date_void              datetime NULL,
    fk_user_void            integer NULL
) ENGINE=innodb;
