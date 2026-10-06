-- Generated PDF/XLSX snapshots of a completed verified second count.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_count_verification_report
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_control              integer NOT NULL,
    sequence                integer NOT NULL,
    line_count              integer DEFAULT 0 NOT NULL,
    content_hash            varchar(64) NOT NULL,
    file_path               varchar(255) NOT NULL,
    status                  smallint DEFAULT 0 NOT NULL,
    datec                   datetime NOT NULL,
    fk_user_author          integer NOT NULL
) ENGINE=innodb;
