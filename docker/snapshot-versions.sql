-- Apply once on existing installations before deploying versioned snapshots.
ALTER TABLE article_snapshots
    ADD version_key CHAR(32) NULL,
    ADD INDEX article_version_order (article_id, captured_at, id),
    ADD INDEX update_version_order (update_id, captured_at, id);

-- Identical old captures get the same key on independently upgraded instances.
-- Exclude IDs and URLs: imports remap record IDs and local PDF paths.
UPDATE article_snapshots
SET version_key = LEFT(SHA2(CONCAT_WS(CHAR(0), title, summary, published_at, article_text, captured_at), 256), 32)
WHERE version_key IS NULL;

ALTER TABLE article_snapshots
    MODIFY version_key CHAR(32) NOT NULL,
    ADD UNIQUE KEY article_snapshot_version (article_id, version_key),
    ADD UNIQUE KEY update_snapshot_version (update_id, version_key),
    DROP INDEX article_id,
    DROP INDEX update_id;

ALTER TABLE articles ADD cache_checked_at DATETIME NULL DEFAULT NULL;
ALTER TABLE article_updates ADD cache_checked_at DATETIME NULL DEFAULT NULL;
