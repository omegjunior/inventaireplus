-- Verified second-count lines tied to the immutable first-count control snapshot.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_count_verification
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_control              integer NOT NULL,
    fk_session              integer NOT NULL,
    fk_contribution         integer NOT NULL,
    fk_inventorydet         integer NOT NULL,
    fk_warehouse            integer NOT NULL,
    fk_product              integer NOT NULL,
    batch                   varchar(128) NULL,
    zone                    varchar(128) NOT NULL,
    qty_first               double(24,8) NOT NULL,
    qty_verified            double(24,8) NULL,
    line_order              integer NOT NULL,
    version                 integer DEFAULT 0 NOT NULL,
    datec                   datetime NOT NULL,
    tms                     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_author          integer NOT NULL,
    fk_user_modif           integer NULL
) ENGINE=innodb;
