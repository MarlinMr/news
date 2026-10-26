#!/usr/bin/env python3
"""List/watch NRK's latest RSS stories, optionally publishing to GeoNews. Python 3.10+."""

import argparse
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
import http.cookiejar
import json
import math
import os
from pathlib import Path
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from zoneinfo import ZoneInfo

FEED = "https://www.nrk.no/nyheter/siste.rss"
USER_AGENT = "GeoNews-NRK-reader/1.0"
OSLO = ZoneInfo("Europe/Oslo")


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []

    def handle_data(self, text):
        self.parts.append(text)

    def handle_starttag(self, tag, attrs):
        if tag in ("p", "div", "br", "li"):
            self.parts.append(" ")

    def handle_endtag(self, tag):
        if tag in ("p", "div", "li"):
            self.parts.append(" ")


def plain_text(value):
    parser = PlainText()
    parser.feed(value)
    return " ".join("".join(parser.parts).split())


def http_url(value):
    parts = urllib.parse.urlsplit(value)
    if parts.scheme not in ("http", "https") or not parts.hostname or parts.username or parts.password:
        raise ValueError("Use an HTTP(S) URL without embedded credentials.")
    return value


def story_key(url):
    parts = urllib.parse.urlsplit(url)
    match = re.search(r"(\d+\.\d+)$", parts.path.rstrip("/"))
    if parts.hostname in ("nrk.no", "www.nrk.no") and match:
        return "nrk:" + match.group(1)  # Headline/URL edits keep the same NRK article ID.
    return url


def parse_feed(content):
    root = ET.fromstring(content)
    if root.tag != "rss" or root.find("channel") is None:
        raise ValueError("Expected an RSS feed, not an HTML page.")
    stories = []
    for item in root.findall("./channel/item"):
        title = plain_text(item.findtext("title", ""))
        url = item.findtext("link", "").strip()
        date = item.findtext("pubDate", "")
        try:
            http_url(url)
            published = parsedate_to_datetime(date)
            if published.tzinfo is None:
                raise ValueError("Publication date needs a timezone.")
        except (ValueError, TypeError, OverflowError):
            continue
        if not title:
            continue
        image = item.find("{http://search.yahoo.com/mrss/}content[@medium='image']")
        image_url = image.get("url", "") if image is not None else ""
        if image_url:
            try:
                http_url(image_url)
            except ValueError:
                image_url = ""
        stories.append({
            "guid": item.findtext("guid", "").strip() or url,
            "title": title, "url": url,
            "summary": plain_text(item.findtext("description", "")),
            "image_url": image_url,
            "published_at": published.astimezone(OSLO).strftime("%Y-%m-%d %H:%M:%S"),
            "timestamp": published.timestamp(),
        })
    return sorted(stories, key=lambda story: story["timestamp"], reverse=True)


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def request(self, url, payload=None, headers=None):
        request = urllib.request.Request(
            url, data=json.dumps(payload).encode() if payload is not None else None,
            headers={"User-Agent": USER_AGENT, **({"Content-Type": "application/json"} if payload is not None else {}), **(headers or {})},
        )
        try:
            with self.opener.open(request, timeout=60) as response:
                return response.read(8 * 1024 * 1024 + 1), response.headers
        except urllib.error.HTTPError as error:
            if error.code == 304:
                raise
            detail = ""
            try:
                detail = json.loads(error.read(4096)).get("error", "")
            except (ValueError, AttributeError):
                pass
            raise RuntimeError(f"HTTP {error.code}: {detail or error.reason}") from error

    def json(self, url, payload=None):
        content, _ = self.request(url, payload)
        return json.loads(content)


class Feed:
    def __init__(self, client, url):
        self.client, self.url = client, url
        self.headers, self.stories = {}, []

    def fetch(self):
        try:
            content, headers = self.client.request(self.url, headers=self.headers)
        except urllib.error.HTTPError as error:
            if error.code != 304:
                raise
            return self.stories  # Retry failed submissions even when RSS is unchanged.
        if len(content) > 8 * 1024 * 1024:
            raise ValueError("RSS response exceeds 8 MB.")
        self.stories = parse_feed(content)
        self.headers = {}
        if headers.get("ETag"):
            self.headers["If-None-Match"] = headers["ETag"]
        if headers.get("Last-Modified"):
            self.headers["If-Modified-Since"] = headers["Last-Modified"]
        return self.stories


class Publisher:
    def __init__(self, client, site, username, password):
        self.client, self.site = client, site.rstrip("/") + "/"
        self.username, self.password = username, password

    def api(self, path, payload=None):
        return self.client.json(self.site + "api/" + path, payload)

    def login(self):
        self.api("auth.php?action=login", {"username_or_email": self.username, "password": self.password})

    def existing_urls(self, stories):
        if not stories:
            return set()
        dates = [story["published_at"][:10] for story in stories]
        query = urllib.parse.urlencode({"start_date": min(dates), "end_date": max(dates), "location": "all"})
        articles = self.api("articles.php?" + query)
        return {story_key(article["url"]) for article in articles}

    def publish(self, story, options):
        metadata = {}
        latitude, longitude = options.latitude, options.longitude
        if options.geocode:
            latitude, longitude = None, None
            try:
                metadata = self.api("parse_og.php?" + urllib.parse.urlencode({"url": story["url"]}))
                place = metadata.get("suggested_place")
                if place:
                    # Respect the public Nominatim limit; also polite for private services.
                    time.sleep(1.1)
                    query = urllib.parse.urlencode({"q": place, "format": "json", "limit": 1})
                    matches = self.client.json(options.geocoder.rstrip("/") + "/search?" + query)
                    if matches:
                        lat, lon = float(matches[0]["lat"]), float(matches[0]["lon"])
                        if not math.isfinite(lat) or not math.isfinite(lon) or abs(lat) > 90 or abs(lon) > 180:
                            raise ValueError("Geocoder returned invalid coordinates.")
                        latitude, longitude = lat, lon
                        print(f"Location suggestion: {place} → {latitude}, {longitude}", file=sys.stderr, flush=True)
            except (RuntimeError, ValueError, OSError, KeyError, TypeError) as error:
                print(f"Location lookup failed; saving to inbox: {error}", file=sys.stderr, flush=True)
        inbox = latitude is None or longitude is None
        result = self.api("articles.php", {
            **({"action": "add_inbox"} if inbox else {}),
            "title": story["title"][:255], "url": story["url"],
            "summary": story["summary"], "image_url": story["image_url"],
            "published_at": story["published_at"],
            "latitude": latitude, "longitude": longitude,
            "category_slug": options.category or metadata.get("category_slug", "general"),
            "injured_count": metadata.get("injured") or 0,
            "killed_count": metadata.get("killed") or 0,
        })
        if inbox:
            print(f"Saved to inbox: {story['title']} [GeoNews #{result['id']}]", file=sys.stderr, flush=True)
        return result["id"]


def open_state(path):
    path = Path(path).expanduser()
    path.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(path, timeout=1)
    db.execute("CREATE TABLE IF NOT EXISTS seen (scope TEXT NOT NULL, url TEXT NOT NULL, PRIMARY KEY (scope, url))")
    return db


def process(stories, options, publisher=None, db=None):
    scope = (publisher.site if publisher else "list:") + options.feed
    selected = stories[:options.limit]
    failed = False
    # Lock the local state through publication so overlapping cron runs share duplicate protection.
    if db is not None:
        db.execute("BEGIN IMMEDIATE")
    try:
        existing = publisher.existing_urls(selected) if publisher else set()
        for story in selected:
            key = story_key(story["url"])
            if db is not None and db.execute("SELECT 1 FROM seen WHERE scope = ? AND url = ?", (scope, key)).fetchone():
                continue
            if publisher and key in existing:
                db.execute("INSERT OR IGNORE INTO seen VALUES (?, ?)", (scope, key))
                continue
            try:
                article_id = publisher.publish(story, options) if publisher else None
                if options.json:
                    print(json.dumps({**{k: v for k, v in story.items() if k != "timestamp"}, **({"article_id": article_id} if publisher else {})}, ensure_ascii=False), flush=True)
                else:
                    suffix = f" [GeoNews #{article_id}]" if publisher else ""
                    print(f"{story['published_at']} Europe/Oslo — {story['title']}{suffix}\n  {story['url']}", flush=True)
                if db is not None:
                    db.execute("INSERT OR IGNORE INTO seen VALUES (?, ?)", (scope, key))
                existing.add(key)
            except (RuntimeError, ValueError, OSError, KeyError) as error:
                failed = True
                print(f"Could not publish {story['url']}: {error}", file=sys.stderr, flush=True)
        if db is not None:
            db.commit()
    except BaseException:
        if db is not None:
            db.rollback()
        raise
    return not failed


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--feed", default=FEED, help="RSS feed URL (default: NRK latest news)")
    parser.add_argument("--limit", type=int, default=20, help="Latest feed entries to consider each poll (default: 20)")
    parser.add_argument("--watch", action="store_true", help="Keep polling and report only unseen stories")
    parser.add_argument("--interval", type=int, default=300, help="Polling interval in seconds, at least 60 (default: 300)")
    parser.add_argument("--json", action="store_true", help="Output one JSON object per story")
    parser.add_argument("--state", default="~/.local/state/geonews/nrk-news.sqlite3", help="Persistent duplicate tracking file")
    parser.add_argument("--site", help="Save stories to this GeoNews base URL (inbox by default); reads GEONEWS_USERNAME and GEONEWS_PASSWORD")
    parser.add_argument("--latitude", type=float, help="Use these fixed coordinates for every published story")
    parser.add_argument("--longitude", type=float)
    parser.add_argument("--geocode", action="store_true", help="Try GeoNews/Nominatim locations; save unlocated stories to the inbox")
    parser.add_argument("--geocoder", default=os.environ.get("NOMINATIM_URL", "https://nominatim.openstreetmap.org"))
    parser.add_argument("--category", help="Override the GeoNews category slug (default: general, or metadata guess with --geocode)")
    options = parser.parse_args(argv)
    if options.limit < 1 or options.interval < 60:
        parser.error("--limit must be positive and --interval must be at least 60 seconds")
    try:
        http_url(options.feed)
        if options.site:
            http_url(options.site)
            if not os.environ.get("GEONEWS_USERNAME") or not os.environ.get("GEONEWS_PASSWORD"):
                parser.error("Set GEONEWS_USERNAME and GEONEWS_PASSWORD to a GeoNews contributor account")
            if options.geocode:
                http_url(options.geocoder)
                if options.latitude is not None or options.longitude is not None:
                    parser.error("Choose either --geocode or fixed coordinates")
            elif options.latitude is not None or options.longitude is not None:
                if options.latitude is None or options.longitude is None:
                    parser.error("Provide both --latitude and --longitude, or neither to use the inbox")
                if not math.isfinite(options.latitude) or not math.isfinite(options.longitude) or abs(options.latitude) > 90 or abs(options.longitude) > 180:
                    parser.error("Invalid coordinates")
        elif options.geocode or options.latitude is not None or options.longitude is not None:
            parser.error("Location options require --site")
    except ValueError as error:
        parser.error(str(error))
    client = Client()
    feed = Feed(client, options.feed)
    publisher = Publisher(client, options.site, os.environ["GEONEWS_USERNAME"], os.environ["GEONEWS_PASSWORD"]) if options.site else None
    db = open_state(options.state) if options.site or options.watch else None
    try:
        while True:
            try:
                if publisher:
                    publisher.login()  # Refresh the session each poll; no database connection needed.
                success = process(feed.fetch(), options, publisher, db)
                if not options.watch:
                    return 0 if success else 1
            except (RuntimeError, ValueError, OSError, ET.ParseError, sqlite3.Error) as error:
                print(f"NRK reader: {error}", file=sys.stderr, flush=True)
                if not options.watch:
                    return 1
            time.sleep(options.interval)
    except KeyboardInterrupt:
        return 0
    finally:
        if db is not None:
            db.close()


if __name__ == "__main__":
    sys.exit(main())
