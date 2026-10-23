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

### Export / Import

Sign in as an **admin**, then open **Export / Import** in the map header (or
`data-transfer.html`). Select an inclusive publication date range and download
the ZIP. On the destination installation, sign in as its admin and upload the
archive on the same page. No connection to the source server is needed to import.

The date range selects **main articles** in Europe/Oslo time. Every selected
incident includes all its follow-ups and comments, including those outside that
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
