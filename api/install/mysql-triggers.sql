DROP TRIGGER IF EXISTS translations_revision_insert;
CREATE TRIGGER translations_revision_insert AFTER INSERT ON translations FOR EACH ROW INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%dT%H:%i:%s.%fZ')) ON DUPLICATE KEY UPDATE meta_value=CAST(CAST(meta_value AS UNSIGNED)+1 AS CHAR),updated_at=VALUES(updated_at);
DROP TRIGGER IF EXISTS translations_revision_update;
CREATE TRIGGER translations_revision_update AFTER UPDATE ON translations FOR EACH ROW INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%dT%H:%i:%s.%fZ')) ON DUPLICATE KEY UPDATE meta_value=CAST(CAST(meta_value AS UNSIGNED)+1 AS CHAR),updated_at=VALUES(updated_at);
DROP TRIGGER IF EXISTS translations_revision_delete;
CREATE TRIGGER translations_revision_delete AFTER DELETE ON translations FOR EACH ROW INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%dT%H:%i:%s.%fZ')) ON DUPLICATE KEY UPDATE meta_value=CAST(CAST(meta_value AS UNSIGNED)+1 AS CHAR),updated_at=VALUES(updated_at);
