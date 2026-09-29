ALTER TABLE llx_inventaireplus_count_control ADD UNIQUE INDEX uk_inventaireplus_count_control_sequence (fk_session, sequence);
ALTER TABLE llx_inventaireplus_count_control ADD INDEX idx_inventaireplus_count_control_status (fk_session, status);
