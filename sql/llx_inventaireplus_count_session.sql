-- Collaborative physical-count campaign attached to a native inventory.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_count_session
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_inventory            integer NOT NULL,
    status                  smallint DEFAULT 0 NOT NULL,
    datec                   datetime NOT NULL,
    tms                     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_author          integer NOT NULL,
    date_close              datetime NULL,
    fk_user_close           integer NULL
) ENGINE=innodb;
