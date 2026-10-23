-- Run once on existing installations to enable portable export/import.
CREATE TABLE IF NOT EXISTS data_transfer_origin (
    id INT PRIMARY KEY,
    origin CHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_transfer_records (
    origin CHAR(32) NOT NULL,
    record_type VARCHAR(16) NOT NULL,
    source_id INT NOT NULL,
    local_id INT NOT NULL,
    PRIMARY KEY (origin, record_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
