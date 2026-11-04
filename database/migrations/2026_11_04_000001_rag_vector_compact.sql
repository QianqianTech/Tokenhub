-- Compact vector copies (V-12): rag_vector_records keeps each vector for reuse and for the MySQL
-- fallback store. New rows store base64 float32 in vector_f32 (about 5.5 KB per 1024-dimension
-- vector instead of about 20 KB of JSON) and leave vector_json NULL. Existing rows stay readable;
-- convert them with `php bin/rag-index.php compact-vectors`.
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_records ADD COLUMN vector_f32 MEDIUMTEXT NULL AFTER vector_json', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_vector_records' AND column_name = 'vector_f32');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
