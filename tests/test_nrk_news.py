import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("nrk_news", Path(__file__).resolve().parents[1] / "scripts" / "nrk_news.py")
nrk = importlib.util.module_from_spec(spec)
spec.loader.exec_module(nrk)

RSS = b'''<rss xmlns:media="http://search.yahoo.com/mrss/"><channel>
<item><title>Older story</title><link>https://www.nrk.no/nyheter/older-1.123</link><pubDate>Mon, 26 Oct 2026 10:00:00 GMT</pubDate></item>
<item><title>New &amp; important</title><link>https://www.nrk.no/nyheter/new-1.124</link><guid>1.124</guid><description>&lt;p&gt;First &lt;strong&gt;paragraph&lt;/strong&gt;.&lt;/p&gt;</description><pubDate>Mon, 26 Oct 2026 12:00:00 GMT</pubDate><media:content medium="image" url="https://gfx.nrk.no/example.jpg" /></item>
<item><title>Invalid date</title><link>https://www.nrk.no/invalid</link><pubDate>not a date</pubDate></item>
<item><title>Invalid link</title><link>javascript:alert(1)</link><pubDate>Mon, 26 Oct 2026 12:00:00 GMT</pubDate></item>
</channel></rss>'''


class NRKNewsTests(unittest.TestCase):
    def setUp(self):
        self.articles = []
        self.posts = []
        self.location = "Trondheim"
        self.reject_post = False
        self.uncertain_post = False
        self.requests = []
        self.candidates = []
        self.refresh_status = 'saved'
        test = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def send_json(self, value, code=200):
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps(value).encode())

            def do_GET(self):
                test.requests.append((self.path, dict(self.headers)))
                if self.path == "/feed":
                    if self.headers.get("If-None-Match") == '"v1"':
                        self.send_response(304)
                        self.end_headers()
                    else:
                        self.send_response(200)
                        self.send_header("ETag", '"v1"')
                        self.end_headers()
                        self.wfile.write(RSS)
                elif self.path.startswith("/news/api/articles.php?action=cache_candidates"):
                    self.send_json({'candidates': test.candidates})
                elif self.path.startswith("/news/api/articles.php"):
                    self.send_json(test.articles)
                elif self.path.startswith("/news/api/parse_og.php"):
                    self.send_json({"suggested_place": test.location, "category_slug": "fires", "injured": 2})
                elif self.path.startswith("/search?"):
                    self.send_json([{"lat": "63.43", "lon": "10.39"}])
                else:
                    self.send_json({"error": "Not found"}, 404)

            def do_POST(self):
                payload = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                test.posts.append((self.path, payload))
                if self.path == "/news/api/auth.php?action=login":
                    self.send_response(200)
                    self.send_header("Set-Cookie", "PHPSESSID=test-session; Path=/news/")
                    self.end_headers()
                    self.wfile.write(b'{"message":"Logged in"}')
                    return
                if self.headers.get("Cookie") != "PHPSESSID=test-session":
                    self.send_json({"error": "Please log in"}, 401)
                elif payload.get('action') == 'refresh_cache':
                    self.send_json({'status': test.refresh_status, 'version_key': 'a' * 32})
                elif test.reject_post:
                    self.send_json({"error": "Temporary failure"}, 503)
                else:
                    test.articles.append(payload)
                    if test.uncertain_post:
                        self.send_json({"error": "Response failed after saving"}, 502)
                    else:
                        self.send_json({"id": len(test.articles)}, 201)

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = "http://127.0.0.1:" + str(self.server.server_port)
        self.client = nrk.Client()
        self.publisher = nrk.Publisher(self.client, self.base + "/news", "reader", "test-password")
        self.publisher.login()
        self.directory = tempfile.TemporaryDirectory()
        self.db = nrk.open_state(Path(self.directory.name) / "state.sqlite3")
        self.options = SimpleNamespace(feed=self.base + "/feed", limit=20, json=False,
                                       latitude=59.9, longitude=10.7, geocode=False,
                                       geocoder=self.base, category=None,
                                       recheck_hours=72, recheck_interval=900, recheck_limit=20)
        self.output = contextlib.ExitStack()
        self.output.enter_context(contextlib.redirect_stdout(io.StringIO()))
        self.output.enter_context(contextlib.redirect_stderr(io.StringIO()))

    def tearDown(self):
        self.output.close()
        self.db.close()
        self.directory.cleanup()
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    def test_feed_parsing_and_sorting(self):
        stories = nrk.parse_feed(RSS)
        self.assertEqual(len(stories), 2)
        self.assertEqual(stories[0]["title"], "New & important")
        self.assertEqual(stories[0]["summary"], "First paragraph.")
        self.assertEqual(stories[0]["published_at"], "2026-10-26 13:00:00")
        self.assertEqual(stories[0]["image_url"], "https://gfx.nrk.no/example.jpg")
        with self.assertRaises(ValueError):
            nrk.parse_feed(b"<html><body>not RSS</body></html>")

    def test_conditional_fetch_keeps_retryable_stories(self):
        feed = nrk.Feed(self.client, self.base + "/feed")
        first = feed.fetch()
        self.assertEqual(feed.fetch(), first)
        self.assertEqual(self.requests[-1][1]["If-None-Match"], '"v1"')

    def test_publish_session_payload_and_repeat(self):
        stories = nrk.parse_feed(RSS)
        self.assertTrue(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)
        self.assertEqual(self.articles[0]["published_at"], "2026-10-26 13:00:00")
        self.assertEqual(self.articles[0]["latitude"], 59.9)
        self.assertEqual(self.articles[0]["summary"], "First paragraph.")
        self.assertTrue(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)
        # RSS headline edits can change URLs without changing NRK's article ID.
        stories[0]["url"] = "https://www.nrk.no/nyheter/changed-headline-1.124"
        nrk.process(stories, self.options, self.publisher, self.db)
        self.assertEqual(len(self.articles), 2)

    def test_server_duplicates_survive_lost_local_state(self):
        stories = nrk.parse_feed(RSS)
        self.articles.append({"url": stories[0]["url"]})
        nrk.process(stories, self.options, self.publisher, self.db)
        self.assertEqual(len(self.articles), 2)

    def test_failed_submissions_are_retried(self):
        stories = nrk.parse_feed(RSS)
        self.reject_post = True
        self.assertFalse(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM seen").fetchone()[0], 0)
        self.reject_post = False
        self.assertTrue(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)

    def test_uncertain_response_does_not_duplicate_saved_posts(self):
        stories = nrk.parse_feed(RSS)
        self.uncertain_post = True
        self.assertFalse(nrk.process(stories, self.options, self.publisher, self.db))
        self.uncertain_post = False
        self.assertTrue(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)

    def test_location_suggestions_and_unlocated_stories(self):
        stories = nrk.parse_feed(RSS)
        self.options.geocode = True
        self.location = None
        self.assertTrue(nrk.process(stories, self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)
        self.assertEqual(self.articles[0]["action"], "add_inbox")
        self.assertIsNone(self.articles[0]["latitude"])
        self.assertIsNone(self.articles[0]["longitude"])
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM seen").fetchone()[0], 2)
        nrk.process(stories, self.options, self.publisher, self.db)
        self.assertEqual(len(self.articles), 2)

    def test_successful_location_suggestion(self):
        stories = nrk.parse_feed(RSS)
        self.options.geocode = True
        self.location = "Trondheim"
        with patch.object(nrk.time, "sleep"):
            nrk.process(stories, self.options, self.publisher, self.db)
        self.assertEqual(self.articles[0]["latitude"], 63.43)
        self.assertEqual(self.articles[0]["category_slug"], "fires")
        self.assertEqual(self.articles[0]["injured_count"], 2)

    def test_inbox_without_geocoding(self):
        self.options.latitude = self.options.longitude = None
        nrk.process(nrk.parse_feed(RSS), self.options, self.publisher, self.db)
        self.assertEqual(self.articles[0]["action"], "add_inbox")
        self.assertIsNone(self.articles[0]["latitude"])

    def test_geocoding_failure_still_saves_to_inbox(self):
        self.options.geocode = True
        original = self.publisher.api
        def fail_metadata(path, payload=None):
            if path.startswith('parse_og.php'):
                raise RuntimeError('Metadata service unavailable')
            return original(path, payload)
        with patch.object(self.publisher, 'api', side_effect=fail_metadata):
            self.assertTrue(nrk.process(nrk.parse_feed(RSS), self.options, self.publisher, self.db))
        self.assertEqual(len(self.articles), 2)
        self.assertEqual(self.articles[0]["action"], "add_inbox")

    def test_duplicate_lookup_includes_inbox(self):
        self.publisher.existing_urls(nrk.parse_feed(RSS))
        self.assertIn('location=all', self.requests[-1][0])

    def test_listing_state_does_not_block_publishing(self):
        stories = nrk.parse_feed(RSS)
        nrk.process(stories, self.options, db=self.db)
        nrk.process(stories, self.options, self.publisher, self.db)
        self.assertEqual(len(self.articles), 2)

    def test_rechecks_saved_stories_absent_from_feed(self):
        self.candidates = [{'id': 999, 'type': 'article'}, {'id': 998, 'type': 'update'}]
        self.assertTrue(self.publisher.recheck_recent(self.options))
        self.assertIn('hours=72', self.requests[-1][0])
        self.assertIn('interval=900', self.requests[-1][0])
        refreshes = [payload for path, payload in self.posts if payload.get('action') == 'refresh_cache']
        self.assertEqual(refreshes, [{'action': 'refresh_cache', 'id': 999, 'type': 'article'}, {'action': 'refresh_cache', 'id': 998, 'type': 'update'}])
        self.assertEqual(self.articles, [])

    def test_rechecks_can_be_disabled(self):
        self.options.recheck_hours = 0
        self.assertTrue(self.publisher.recheck_recent(self.options))
        self.assertEqual(self.requests, [])

    def test_older_server_reports_missing_recheck_support(self):
        with patch.object(self.publisher, 'api', return_value=[]):
            with self.assertRaisesRegex(RuntimeError, 'does not support cache rechecks'):
                self.publisher.recheck_recent(self.options)

    def test_unavailable_recheck_does_not_create_an_article(self):
        self.candidates = [{'id': 999, 'type': 'article'}]
        self.refresh_status = 'unavailable'
        self.assertTrue(self.publisher.recheck_recent(self.options))
        self.assertEqual(self.articles, [])

    def test_main_rechecks_even_if_feed_fails(self):
        environment = {'GEONEWS_USERNAME': 'reader', 'GEONEWS_PASSWORD': 'test-password'}
        with patch.dict('os.environ', environment), patch.object(nrk.Feed, 'fetch', side_effect=RuntimeError('Feed unavailable')), patch.object(nrk.Publisher, 'recheck_recent', return_value=True) as recheck:
            status = nrk.main(['--site', self.base + '/news/', '--state', str(Path(self.directory.name) / 'main.sqlite3')])
        self.assertEqual(status, 1)
        recheck.assert_called_once()


if __name__ == "__main__":
    unittest.main()
