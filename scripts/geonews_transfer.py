"""Shared standard-library HTTP helpers for the GeoNews export/import commands."""

import argparse
import getpass
import http.cookiejar
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

MAX_ARCHIVE_BYTES = 128 * 1024 * 1024
CHUNK_BYTES = 1024 * 1024


def argument_parser(description):
    parser = argparse.ArgumentParser(description=description)
    parser.add_argument("--site", required=True, help="Instance base URL, including /news/ if applicable")
    parser.add_argument("--username", default=os.environ.get("GEONEWS_USERNAME"), help="Admin username/email (default: GEONEWS_USERNAME)")
    parser.add_argument("--timeout", type=float, default=360, help="HTTP timeout in seconds (default: 360)")
    return parser


def connect(options):
    client = TransferClient(options.site, options.timeout)
    username = options.username
    if not username:
        raise ValueError("Provide --username or set GEONEWS_USERNAME to an admin account.")
    password = os.environ.get("GEONEWS_PASSWORD")
    if not password:
        if not sys.stdin.isatty():
            raise ValueError("Set GEONEWS_PASSWORD when running non-interactively.")
        password = getpass.getpass(f"Password for {username}: ")
    client.json("auth.php?action=login", {"username_or_email": username, "password": password})
    # The info endpoint enforces admin access and checks required tables/extensions.
    info = client.json("data-transfer.php?action=info")
    return client, info


class TransferClient:
    def __init__(self, site, timeout=360):
        parts = urllib.parse.urlsplit(site)
        if (parts.scheme not in ("http", "https") or not parts.hostname
                or parts.username or parts.password or parts.query or parts.fragment):
            raise ValueError("Use an HTTP(S) instance base URL without credentials, query, or fragment.")
        if timeout <= 0:
            raise ValueError("Timeout must be positive.")
        self.base = site.rstrip("/") + "/api/"
        self.timeout = timeout
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def open(self, endpoint, data=None, headers=None):
        request = urllib.request.Request(self.base + endpoint, data=data, headers={
            "User-Agent": "GeoNews-dataset-transfer/1.0", **(headers or {})
        })
        try:
            return self.opener.open(request, timeout=self.timeout)
        except urllib.error.HTTPError as error:
            body = error.read(65536)
            try:
                detail = json.loads(body).get("error")
            except (ValueError, AttributeError):
                detail = None
            raise RuntimeError(f"HTTP {error.code}: {detail or error.reason}") from error

    @staticmethod
    def read_json(response):
        try:
            data = json.load(response)
        except ValueError as error:
            raise RuntimeError("The server did not return JSON. Check the base URL and server/proxy limits.") from error
        if not isinstance(data, dict):
            raise RuntimeError("Unexpected API response.")
        if data.get("error"):
            raise RuntimeError(str(data["error"]))
        return data

    def json(self, endpoint, payload=None):
        body = json.dumps(payload).encode() if payload is not None else None
        headers = {"Content-Type": "application/json"} if body is not None else None
        with self.open(endpoint, body, headers) as response:
            return self.read_json(response)

    def import_archive(self, archive, token):
        archive = Path(archive)
        boundary = "geonews-" + uuid.uuid4().hex
        prefix = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"archive\"; filename=\"archive.zip\"\r\n"
                  "Content-Type: application/zip\r\n\r\n").encode()
        suffix = f"\r\n--{boundary}--\r\n".encode()
        size = archive.stat().st_size

        def chunks():
            yield prefix
            with archive.open("rb") as stream:
                remaining = size
                while remaining:
                    chunk = stream.read(min(CHUNK_BYTES, remaining))
                    if not chunk:
                        raise OSError("Archive changed or was truncated while uploading.")
                    remaining -= len(chunk)
                    yield chunk
            yield suffix

        headers = {"Content-Type": "multipart/form-data; boundary=" + boundary,
                   "Content-Length": str(len(prefix) + size + len(suffix)),
                   "X-Transfer-Token": token}
        with self.open("data-transfer.php?action=import", chunks(), headers) as response:
            return self.read_json(response)
