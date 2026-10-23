-- Run once on existing databases before deploying the cached article feature.
CREATE TABLE IF NOT EXISTS article_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NULL UNIQUE,
    update_id INT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    summary TEXT NOT NULL,
    published_at DATETIME NOT NULL,
    article_text MEDIUMTEXT NOT NULL,
    captured_at DATETIME NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY (update_id) REFERENCES article_updates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
