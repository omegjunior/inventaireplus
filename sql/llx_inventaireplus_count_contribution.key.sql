ALTER TABLE llx_inventaireplus_count_contribution ADD UNIQUE INDEX uk_inventaireplus_count_scan (fk_session, scan_key);
ALTER TABLE llx_inventaireplus_count_contribution ADD INDEX idx_inventaireplus_count_line (fk_session, fk_inventorydet, active);
ALTER TABLE llx_inventaireplus_count_contribution ADD INDEX idx_inventaireplus_count_user (fk_session, fk_user_author);
