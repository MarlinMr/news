#!/usr/bin/env python3
"""Upload a GeoNews dataset ZIP to another instance using the existing admin import API."""

from pathlib import Path
import sys
import zipfile

from geonews_transfer import MAX_ARCHIVE_BYTES, argument_parser, connect


def main(argv=None):
    parser = argument_parser(__doc__)
    parser.add_argument("archive", help="GeoNews ZIP archive produced by export_dataset.py or the website")
    options = parser.parse_args(argv)
    try:
        archive = Path(options.archive).expanduser()
        size = archive.stat().st_size
        if size <= 0 or size > MAX_ARCHIVE_BYTES:
            raise ValueError("Archive must contain 1 byte to 128 MB. Export smaller date ranges if necessary.")
        with zipfile.ZipFile(archive) as bundle:
            if "data.json" not in bundle.namelist():
                raise ValueError("Not a GeoNews archive: data.json is missing.")
        client, info = connect(options)
        if size > min(info["max_archive_bytes"], MAX_ARCHIVE_BYTES):
            raise ValueError("Archive exceeds the destination's upload limit.")
        result = client.import_archive(archive, info["token"])
        counts = result["counts"]
        print("Import complete: " + ", ".join(f"{counts[key]} {key}" for key in ("articles", "updates", "comments", "snapshots", "pdfs"))
              + f"; {counts['skipped']} previously imported records kept.")
        return 0
    except (OSError, ValueError, RuntimeError, zipfile.BadZipFile, KeyError) as error:
        print(f"Import failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
