-- GeoNews Map Database Schema & Initial Test Data
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- CREATE DATABASE IF NOT EXISTS `geonews` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `geonews`;

-- Drop existing tables if re-initializing
DROP TABLE IF EXISTS `article_updates`;
DROP TABLE IF EXISTS `articles`;
DROP TABLE IF EXISTS `users`;

-- 1. Users Table
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('user', 'moderator', 'admin') NOT NULL DEFAULT 'user',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Master Articles Table
CREATE TABLE `articles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `summary` TEXT,
    `url` VARCHAR(500) NOT NULL,
    `image_url` VARCHAR(500) DEFAULT NULL,
    `emoji` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '📰',
    `category_slug` VARCHAR(50) NOT NULL DEFAULT 'general',
    `latitude` DECIMAL(10,8) NOT NULL,
    `longitude` DECIMAL(11,8) NOT NULL,
    `injured_count` INT NOT NULL DEFAULT 0,
    `killed_count` INT NOT NULL DEFAULT 0,
    `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Article Updates (Sub-Timeline Events) Table
CREATE TABLE `article_updates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `article_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `source_name` VARCHAR(100) DEFAULT NULL,
    `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Sample Users (password is 'password123' for all, using PHP native $2y$ BCRYPT prefix)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`) VALUES
(1, 'admin', 'admin@geonews.local', '$2y$10$o.PmwSmlXpFcn36sIMH45OZYuwOLPgYO.Sdo/Gk/Q/wm.6I9WR/CS', 'admin'),
(2, 'moderator', 'mod@geonews.local', '$2y$10$o.PmwSmlXpFcn36sIMH45OZYuwOLPgYO.Sdo/Gk/Q/wm.6I9WR/CS', 'moderator'),
(3, 'reporter', 'reporter@geonews.local', '$2y$10$o.PmwSmlXpFcn36sIMH45OZYuwOLPgYO.Sdo/Gk/Q/wm.6I9WR/CS', 'user');

-- Seed Initial Test Incidents
INSERT INTO `articles` (`id`, `user_id`, `title`, `summary`, `url`, `image_url`, `emoji`, `category_slug`, `latitude`, `longitude`, `injured_count`, `killed_count`, `published_at`) VALUES
(1, 1, 'Trafikkulykke på E18 ved Drammen', 'Trevognskollisjon på E18 forårsaker lange køer i sørgående retning.', 'https://www.nrk.no', 'https://images.unsplash.com/photo-1542282088-72c9c27ed0cd', '💥', 'collisions', 59.744074, 10.204456, 3, 0, NOW()),
(2, 2, 'Bygningsbrann i Sentrum', 'Kraftig røkutvikling fra næringsbygg i Oslo sentrum.', 'https://www.vg.no', NULL, '🔥', 'fires', 59.913868, 10.752245, 1, 0, NOW()),
(3, 1, 'Jordskred sperrer fylkesvei', 'Mindre stein- og jordras har stengt veien for trafikk.', 'https://www.aftenposten.no', NULL, '⛰️', 'landslides', 60.391263, 5.322054, 0, 0, NOW());

-- Seed Timeline Updates
INSERT INTO `article_updates` (`id`, `article_id`, `user_id`, `title`, `url`, `source_name`, `published_at`) VALUES
(1, 1, 1, 'Politiet melder om gjenåpnet venstre felt', 'https://www.nrk.no', 'NRK Nyheter', NOW()),
(2, 1, 2, 'Bilberging fullført, normal trafikkavvikling', 'https://www.vg.no', 'VG Live', NOW());
