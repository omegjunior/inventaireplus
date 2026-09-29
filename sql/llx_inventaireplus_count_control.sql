-- Immutable control-sheet snapshots used by the four-eyes workflow.
CREATE TABLE IF NOT EXISTS llx_inventaireplus_count_control
(
    rowid                   integer AUTO_INCREMENT PRIMARY KEY,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_session              integer NOT NULL,
    sequence                integer NOT NULL,
    contribution_max_id     integer DEFAULT 0 NOT NULL,
    contribution_count      integer DEFAULT 0 NOT NULL,
    content_hash            varchar(64) NOT NULL,
    file_path               varchar(255) NOT NULL,
    status                  smallint DEFAULT 0 NOT NULL,
    datec                   datetime NOT NULL,
    fk_user_author          integer NOT NULL,
    date_approval           datetime NULL,
    fk_user_approval        integer NULL
) ENGINE=innodb;
