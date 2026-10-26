# GeoNews

## Docker

Set overrides in a `.env` file beside `docker-compose.yml`, then run:

```sh
docker compose up -d --build
```

The site is available at `http://localhost:8080`.

### Database

The web container and MariaDB initialization use `MARIADB_DATABASE`,
`MARIADB_USER`, and `MARIADB_PASSWORD`. The web container also accepts
`MARIADB_HOST` (default `db`) and `MARIADB_PORT` (default `3306`).
`MARIADB_ROOT_PASSWORD` configures the database container's root password.
The Docker database connection still accepts legacy `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, and `DB_PASS` when the corresponding `MARIADB_*` variable is unset.

MariaDB initialization variables apply to a new database volume. For an existing
database, set these values to its existing credentials or update the database
user/password separately.

The Docker build installs `docker/db.php` as `api/db.php`. Local `api/db.php`
and `api/config.php` files are excluded from the image build.

### Non-Docker installations

Keep your existing local `api/db.php`; it remains ignored by Git and is the
database connection used by the API. For a new installation, copy
`api/db.php.example` to `api/db.php` and enter your database settings.

For PDF uploads, make the site's `pdf/` directory writable by the PHP process.
Set PHP's `upload_max_filesize` to `20M` and `post_max_size` to at least `21M`
to support the full upload limit.

### PDF uploads

Logged-in users can select a PDF instead of a URL in either the article or
timeline update dialog. Files are uploaded when saving; enter the title,
summary, and publication date manually. The maximum upload size is 20 MB.

PDFs have generated filenames and site-relative links (`pdf/<filename>.pdf`),
so installations under a path such as `/news/` serve them under `/news/pdf/`.
Inside the Docker container they are stored in `/pdf`. Compose mounts the
`pdf_data` named volume there, preserving files when the container is recreated.
For a standalone Docker deployment, mount a named volume with `-v geonews_pdf:/pdf`.
For a host directory, mount it at `/pdf` and make it writable by `www-data`
(UID 33). PDF files are retained when articles are deleted.

### Cached articles

The popup's **Cached article** link opens a local, text-only page containing the
main article and all its follow-up articles in publication order. Each new entry
captures the publicly accessible article text when it is saved. The saved title,
URL, summary, publication date, and text are preserved through subsequent edits
and metadata refreshes. Deleting an entry also deletes its snapshot.

**Existing installations:** apply `docker/article-cache.sql` to your database
once before deploying this feature. For a Compose installation:

```sh
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < docker/article-cache.sql
```

New databases include the table in `docker/schema.sql`. Older entries have no
historical snapshot and are labeled accordingly; they are not fetched on page
views. Failed captures do not prevent saving an article. Captures can be partial,
and sites requiring JavaScript or a subscription may not provide readable text.
Uploaded PDFs link to the stored PDF instead of extracting its text.

### Unlocated inbox

Open **Menu → Inbox** to read saved stories which do not yet have coordinates.
The inbox includes all dates and categories and links to original and cached
articles. **Any signed-in user** can choose **Place on map**, click a location,
and confirm **Place here**. Anonymous visitors can browse the inbox and sign in
to place a story. Use Cancel or Escape to exit placement mode. After placement,
the story leaves the inbox and appears on the map; the map filters expand if
needed to reveal it. The original author and cached snapshot are preserved.
Editing, moving an already placed pin, and deleting retain the normal ownership
and moderator/admin permissions. Concurrent placements are resolved atomically:
the first placement succeeds and subsequent attempts receive a conflict.

**Existing installations:** apply `docker/inbox.sql` before deploying this feature
on both online and offline databases:

```sql
ALTER TABLE articles
    MODIFY latitude DECIMAL(10,8) NULL DEFAULT NULL,
    MODIFY longitude DECIMAL(11,8) NULL DEFAULT NULL;
```

Both coordinates must be null for an inbox story, or both must contain valid map
coordinates. No placeholder pins at `(0, 0)` are created. New databases use the
updated `docker/schema.sql` automatically. Inbox stories and their cached text
are included in date-range exports. New exports use archive format version 2;
updated installations can still import version 1 archives. Upgrade the destination
application and schema before importing version 2 archives.

For API clients, `POST api/articles.php` with `action: "add_inbox"` and the usual
title, URL, summary, etc. saves an unlocated story. `GET ?location=inbox` lists
all inbox stories; `GET ?location=all` includes mapped and unlocated articles
within the requested date range. The default GET lists only mapped articles.
`POST` with `action: "place_inbox"`, `id`, `latitude`, and `longitude` places a
story using any signed-in account.

### Export / Import

Sign in as an **admin**, then open **Export / Import** in the map header (or
`data-transfer.html`). Select an inclusive publication date range and download
the ZIP. On the destination installation, sign in as its admin and upload the
archive on the same page. No connection to the source server is needed to import.

The date range selects **main articles**, including inbox stories, in Europe/Oslo
time. Every selected incident includes all its follow-ups and comments, including those outside that
range, plus original cached text snapshots and uploaded PDFs. Remote thumbnails
are kept as URLs, not downloaded. Source passwords, emails, and roles are excluded;
authors become separate contributor accounts with random, unknown passwords and
no administrative privileges. Username collisions receive a source suffix.

Imports merge rather than replace the destination database. Source IDs are
mapped to new local IDs. Repeated or overlapping imports from the same source
skip previously imported records, preserving local edits, while adding new
articles, follow-ups, comments and missing snapshots. This is an additive transfer,
not synchronization of edits or deletions. Export/import history is stored in
`data_transfer_origin` and `data_transfer_records`; retain these tables with your
database. Importing an installation's own archive back into itself is rejected.

**Existing installations:** apply `docker/data-transfer.sql` on both source and
destination databases. Also apply `docker/article-cache.sql` if not already done.
Apply `docker/inbox.sql` as well if upgrading an existing installation.
New databases include all tables in `docker/schema.sql`.

PHP needs the **zip** extension (`ZipArchive`), writable temporary storage, and
writable `pdf/` storage. Docker installs and configures these automatically.
For non-Docker installations, enable PHP zip and set `upload_max_filesize=128M`,
`post_max_size=129M`, and `memory_limit=512M` (also adjust proxy upload limits).
PDF uploads themselves retain their 20 MB limit. Each transfer supports up to
128 MB compressed, 256 MB expanded, and 64 MB of JSON; use smaller date ranges
for larger datasets. Missing referenced PDFs cause export to fail rather than
silently omitting offline content. Failed imports roll back database changes and
remove newly written PDFs.

Cached-article pages and included PDFs work without contacting publishers.
A completely offline **map** additionally needs local copies of the application's
CDN-hosted JavaScript/CSS libraries and locally available map tiles. Those assets
and map-service settings are not part of the data archive.

#### Export / Import from Python

Two command-line scripts use the same authenticated API as the export/import
page. They require **Python 3.10+**, no third-party packages, and an **admin account
on each instance**. Keep `scripts/geonews_transfer.py` beside the two scripts.

Export a publication date range from the source:

```sh
export GEONEWS_USERNAME='source-admin'
export GEONEWS_PASSWORD='SOURCE_PASSWORD'
python3 scripts/export_dataset.py --site https://SOURCE_HOST/news/ \
  --start-date 2026-10-01 --end-date 2026-10-26 --output news.zip
```

Omit both date flags to export the full available date range. Dates are inclusive
and use Europe/Oslo time, with the same follow-up/snapshot/PDF selection as the
website. Existing output files are preserved unless `--overwrite` is supplied;
failed downloads leave no partial output archive.

Transfer the ZIP to the offline machine, then import into its local instance:

```sh
export GEONEWS_USERNAME='destination-admin'
export GEONEWS_PASSWORD='DESTINATION_PASSWORD'
python3 scripts/import_dataset.py --site http://localhost:8080/ news.zip
```

`--username` can replace `GEONEWS_USERNAME`. If `GEONEWS_PASSWORD` is unset,
interactive runs prompt for it without displaying it. Set the environment
variables for unattended runs. Both commands support `--timeout SECONDS` (default
360), exit nonzero on failure, and stream archive transfers instead of loading
the entire ZIP into memory. The import script obtains the required session token
automatically. The destination only needs access to its own local application;
it does not contact the source instance or publishers during import.

These commands share the website's merge/duplicate behavior and server-side
requirements described above. They need no additional SQL or API changes, and
are run externally rather than by the GitHub deployment workflow.

### NRK news reader (external Python script)

`scripts/nrk_news.py` uses the RSS feed linked from
<https://www.nrk.no/nyheter/>. Run it on any machine with **Python 3.10+** and
timezone data for Europe/Oslo; no third-party Python packages are required.

List the latest ten stories:

```sh
python3 scripts/nrk_news.py --limit 10
```

Watch for new stories every five minutes (Ctrl+C stops it):

```sh
python3 scripts/nrk_news.py --watch --interval 300
```

Add `--json` for JSON-lines output. Watching is polling the RSS feed, not a push
subscription. The script uses conditional HTTP requests when NRK supplies cache
validators. RSS is a rolling window, so running it after a long downtime cannot
recover stories which have already left the feed.

To **save stories to GeoNews**, create a normal contributor account on the destination
site, set its credentials in the external process environment, then supply the
site's base URL (including `/news/` if applicable):

```sh
export GEONEWS_USERNAME='nrk-reader'
export GEONEWS_PASSWORD='YOUR_PASSWORD'
python3 scripts/nrk_news.py --watch --site https://YOUR_HOST/news/ --geocode
```

`--geocode` uses the site's existing metadata place-name suggestion and Nominatim.
Location suggestions can be wrong; stories with no suggestion, no geocoding
result, or a failed location lookup are **saved to the inbox** for any signed-in
user to place on the map. The script logs whether it saved to the inbox and any
coordinates it chose. Correct pins on the map as needed. Omit `--geocode` and
coordinate options to save **every story directly to the inbox**. To use your own
geocoder, pass
`--geocoder https://YOUR_NOMINATIM_HOST` or set `NOMINATIM_URL`.

Alternatively, explicitly assign **every story** to fixed coordinates:

```sh
python3 scripts/nrk_news.py --site https://YOUR_HOST/news/ \
  --latitude 59.9139 --longitude 10.7522
```

Use fixed coordinates only for a feed you want grouped at that location. They do
not identify where each incident occurred. `--category fires` overrides the
category; otherwise it uses the metadata category with geocoding, or `general`.
`--feed URL` selects another RSS feed. Without `--watch`, publishing runs once,
which also works with an external scheduler. The initial run considers the latest
20 stories (`--limit` changes this); subsequent runs publish only unseen stories.

Seen stories are stored in `~/.local/state/geonews/nrk-news.sqlite3`. Keep this file
between runs, or choose an explicit path with `--state /path/to/nrk.sqlite3`.
The reader also checks existing destination article URLs before publishing, and
recognizes NRK article IDs even after headline/URL changes. Failed submissions
are retried on the next poll. Run one reader/state file per destination; this is
not a server-wide uniqueness constraint across separate machines.

Publishing uses the existing authenticated API, so it needs no database access
or additional website endpoint. Apply the inbox migration described above before
using the updated watcher. Normal article saving captures
cached text as usual. New stories appear when the map is loaded/refreshed, within
its selected date/category filters; unlocated stories appear under Menu → Inbox.
Each story is a separate main article; follow-ups
are not automatically grouped. This external script is not installed or started
by the GitHub website deployment workflow; run `--watch` under your own process
manager if you want it to stay running.

Tests: `python3 -m unittest discover -s tests -p 'test_nrk_news.py'`.

### Map services

Example `.env` overrides:

```dotenv
NOMINATIM_URL=https://geocoder.example.com
TILE_SERVER_URL=https://tiles.example.com/{z}/{x}/{y}.png
TILE_SERVER_ATTRIBUTION=Map data attribution
```

`NOMINATIM_URL` is the base URL; the app appends `/search` for both location
search and suggested article locations. It must be reachable by users' browsers
and allow cross-origin requests when hosted on another origin.

`TILE_SERVER_URL` replaces the default OpenStreetMap layer.
To customize several layers or add new ones, set `TILE_SERVERS` to a JSON object
keyed by layer ID. Existing IDs are `osm`, `topo`, `graatone`, `toporaster`,
`sjokart`, and `opentopomap`. Each entry accepts a `name`, a `url`, and Leaflet
tile options such as `attribution`, `maxNativeZoom`, and `subdomains`:

```dotenv
TILE_SERVERS='{"local":{"name":"Local map","url":"https://tiles.example.com/{z}/{x}/{y}.png","attribution":"Local map provider","maxNativeZoom":18}}'
RAILWAY_TILE_URL=https://rail.example.com/{z}/{x}/{y}.png
```

Custom layers appear in the map style selector. These settings are read at
runtime; recreate the web container after changing its environment variables.
