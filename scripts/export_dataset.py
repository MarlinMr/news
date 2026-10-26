#!/usr/bin/env python3
"""Download a GeoNews dataset ZIP using the existing admin export API."""

from datetime import date
import os
from pathlib import Path
import sys
import tempfile
import urllib.parse
import zipfile

from geonews_transfer import CHUNK_BYTES, MAX_ARCHIVE_BYTES, argument_parser, connect


def date_argument(value):
    if date.fromisoformat(value).isoformat() != value:
        raise ValueError("Dates must use YYYY-MM-DD.")
    return value


def download(client, start, end, output, overwrite=False):
    output = Path(output).expanduser()
    if output.exists() and not overwrite:
        raise FileExistsError(f"Output already exists: {output}. Use --overwrite to replace it.")
    query = urllib.parse.urlencode({"action": "export", "start_date": start, "end_date": end})
    temporary = None
    try:
        with client.open("data-transfer.php?" + query) as response:
            if response.headers.get_content_type() != "application/zip":
                client.read_json(response)
                raise RuntimeError("The server did not return a ZIP archive.")
            with tempfile.NamedTemporaryFile(dir=output.parent, prefix="." + output.name + ".", suffix=".part", delete=False) as stream:
                temporary = Path(stream.name)
                size = 0
                while chunk := response.read(CHUNK_BYTES):
                    size += len(chunk)
                    if size > MAX_ARCHIVE_BYTES:
                        raise ValueError("Archive exceeds 128 MB. Export a smaller date range.")
                    stream.write(chunk)
            expected = response.headers.get("Content-Length")
            if expected is not None and size != int(expected):
                raise RuntimeError("Download was incomplete; no output archive was saved.")
        with zipfile.ZipFile(temporary) as archive:
            if "data.json" not in archive.namelist():
                raise ValueError("Downloaded archive is missing data.json.")
        if overwrite:
            os.replace(temporary, output)
        else:
            # Same-directory hard link publishes atomically without clobbering an existing file.
            os.link(temporary, output)
        return size
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def main(argv=None):
    parser = argument_parser(__doc__)
    parser.add_argument("--start-date", help="Inclusive first publication date, YYYY-MM-DD (default: earliest article)")
    parser.add_argument("--end-date", help="Inclusive last publication date, YYYY-MM-DD (default: latest article)")
    parser.add_argument("--output", "-o", required=True, help="Destination ZIP filename")
    parser.add_argument("--overwrite", action="store_true", help="Replace an existing output file after a successful download")
    options = parser.parse_args(argv)
    try:
        if options.start_date:
            date_argument(options.start_date)
        if options.end_date:
            date_argument(options.end_date)
        if options.start_date and options.end_date and options.start_date > options.end_date:
            raise ValueError("Start date must be on or before end date.")
        client, info = connect(options)
        start = options.start_date or info["range"]["start_date"] or date.today().isoformat()
        end = options.end_date or info["range"]["end_date"] or date.today().isoformat()
        if start > end:
            raise ValueError("Start date must be on or before end date.")
        size = download(client, start, end, options.output, options.overwrite)
        print(f"Exported {start} through {end} (Europe/Oslo) to {options.output} ({size:,} bytes).")
        return 0
    except (OSError, ValueError, RuntimeError, zipfile.BadZipFile, KeyError) as error:
        print(f"Export failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
