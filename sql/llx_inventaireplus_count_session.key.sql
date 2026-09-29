ALTER TABLE llx_inventaireplus_count_session ADD UNIQUE INDEX uk_inventaireplus_count_session_inventory (entity, fk_inventory);
ALTER TABLE llx_inventaireplus_count_session ADD INDEX idx_inventaireplus_count_session_status (entity, status);
