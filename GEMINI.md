# GeoNews Map — Project Architecture & Handover Specs

## 📌 Project Overview
GeoNews Map is a web-based, crowdsourced interactive news map. It plots live news incidents geographically using Leaflet.js, supporting real-time web scraping, multi-source event timelines, automated casualty extraction, category classification, and human-in-the-loop review moderation.

---

## 🏗️ System Architecture

### 1. Technology Stack
* **Frontend:** Vanilla HTML5, CSS3, JavaScript (ES6+), Leaflet.js 1.9, Leaflet.markercluster 1.5, noUiSlider 15.7.
* **Backend:** PHP 8.x (REST API layer), MySQL / MariaDB (PDO, utf8mb4 character set).
* **Geocoding:** OpenStreetMap Nominatim API.
* **Metadata Scraper:** PHP `DOMDocument` + `DOMXPath` (Open Graph tag parsing).

---

## 🗄️ Database Schema

### `users`
* `id` (INT, Primary Key, Auto Increment)
* `username` (VARCHAR 50, Unique)
* `email` (VARCHAR 100, Unique)
* `password_hash` (VARCHAR 255)
* `role` (ENUM: `'user'`, `'moderator'`, `'admin'`, Default `'user'`)
* `created_at` (TIMESTAMP)

### `articles` (Master Incidents)
* `id` (INT, Primary Key, Auto Increment)
* `user_id` (INT, Foreign Key -> `users.id`)
* `title` (VARCHAR 255)
* `summary` (TEXT)
* `url` (VARCHAR 500)
* `image_url` (VARCHAR 500, Nullable)
* `emoji` (VARCHAR 10) — Display symbol for map pins (e.g. `💥`)
* `category_slug` (VARCHAR 50) — **Single Source of Truth for Filtering** (e.g. `collisions`)
* `latitude` (DECIMAL 10,8)
* `longitude` (DECIMAL 11,8)
* `injured_count` (INT, Default 0)
* `killed_count` (INT, Default 0)
* `published_at` (DATETIME)

### `article_updates` (Sub-Timeline Events)
* `id` (INT, Primary Key, Auto Increment)
* `article_id` (INT, Foreign Key -> `articles.id` ON DELETE CASCADE)
* `user_id` (INT, Foreign Key -> `users.id`)
* `title` (VARCHAR 255)
* `url` (VARCHAR 500)
* `source_name` (VARCHAR 100, Nullable)
* `published_at` (DATETIME)

---

## ⚙️ Core API Endpoints (`/api/`)

| Endpoint | Method | Params / Payload | Description |
|---|---|---|---|
| `db.php` | - | - | Central PDO connection. Must enforce `charset=utf8mb4`. |
| `articles.php` | `GET` | `start_date`, `end_date` | Fetches master articles with grouped timeline updates. |
| `articles.php` | `POST` | Article JSON | Creates or edits a master pin (auto-maps `category_slug` -> `emoji`). |
| `articles.php` | `POST` | `{ action: "add_update", ... }` | Appends a sub-article update to an existing incident timeline. |
| `articles.php` | `DELETE` | `id`, `type` (`article` \| `update`) | Deletes master pin or timeline item (Owner or Admin/Mod required). |
| `parse_og.php` | `GET` | `url` | Web scraper. Returns Open Graph title, description, image, date, casualty counts, guessed `category_slug`, and guessed place name. |
| `auth.php` | `POST` | `username`, `password` | Authentication management (`register`, `login`, `logout`, `me`). |

---

## 🌟 Key Frontend Workflows

1. **Category Mapping:** Plain-text `category_slug` values drive all URL query parameters (`?category=collisions`) and filter logic. Display emojis are auto-populated by the backend `$categoryEmojiMap`.
2. **Metadata Scraper & Auto-Guessing:**
   * Scans text for Norwegian & English casualty patterns (e.g. *"3 skadd"*, *"two dead"*).
   * Runs keyword-matching rules to guess the category slug (`collisions`, `fires`, `crime`, etc.).
   * Extracts location prepositions (e.g. *"i Drammen"*) and queries Nominatim to suggest alternative coordinates.
3. **Refresh Review Module (Human-in-the-Loop):**
   * Clicking **"🔄 Refresh Data"** re-scrapes the live article URL.
   * Compares stored vs. scraped fields in a visual diff container.
   * Provides **`[↺ Keep Old]`** rollback buttons next to changed fields so moderators can mix original and new live data before saving.
4. **Timeline Date Navigation:**
   * Features a dual-thumb `noUiSlider` slider with quick preset buttons (`7D`, `1M`, `6M`, `1Y`, `ALL`).
   * Supports mouse-wheel scrolling over the slider to nudge date windows in 7-day increments.

---

## 🚀 Future Migration Considerations
When refactoring or migrating to modern frameworks (e.g. React, Vue, Svelte, or Node.js):
* Retain the `category_slug` as the canonical identifier in APIs and route state; do not rely on raw UTF-8 emoji strings for DB queries.
* Maintain strict CORS header evaluation prior to loading database drivers in serverless/PHP handlers to prevent preflight errors.
