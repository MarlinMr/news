-- Apply on existing installations before deploying inbox support.
-- NULL coordinates mean the story is saved but has not yet been placed on the map.
ALTER TABLE articles
    MODIFY latitude DECIMAL(10,8) NULL DEFAULT NULL,
    MODIFY longitude DECIMAL(11,8) NULL DEFAULT NULL;
