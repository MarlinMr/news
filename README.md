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
