-- Run once on existing installations, after the snapshot-versions migration.
-- Intentionally no FK: deleting an update must never resurrect its old article.
ALTER TABLE articles ADD COLUMN converted_update_id INT NULL DEFAULT NULL,
    ADD INDEX article_converted_update (converted_update_id);
