import contextlib
import io
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

with patch.object(sys, "path", [str(Path(__file__).resolve().parents[1] / "scripts"), *sys.path]):
    import bbc_news as bbc
    import nrk_news as reader


RSS = b'''<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>
<title>BBC story</title>
<link>https://www.bbc.co.uk/news/articles/example?at_medium=RSS</link>
<description>&lt;p&gt;Story summary&lt;/p&gt;</description>
<pubDate>Mon, 26 Oct 2026 12:34:00 GMT</pubDate>
<media:thumbnail url="https://ichef.bbci.co.uk/example.jpg" />
</item></channel></rss>'''


class BBCNewsTests(unittest.TestCase):
    def test_thumbnail_and_oslo_date(self):
        story, = reader.parse_feed(RSS)
        self.assertEqual(story["image_url"], "https://ichef.bbci.co.uk/example.jpg")
        self.assertEqual(story["published_at"], "2026-10-26 13:34:00")
        self.assertEqual(story["summary"], "Story summary")

    def test_bbc_identity_ignores_domain_tracking_and_fragment(self):
        self.assertEqual(
            reader.story_key("https://www.bbc.co.uk/news/articles/example?at_medium=RSS#0"),
            reader.story_key("https://www.bbc.com/news/articles/example"),
        )
        self.assertNotEqual(reader.story_key("https://example.com/news/articles/example"),
                            reader.story_key("https://www.bbc.com/news/articles/example"))

    def test_does_not_fall_back_to_nrk_credentials(self):
        with patch.dict(os.environ, {"GEONEWS_USERNAME": "nrk", "GEONEWS_PASSWORD": "secret"}, clear=True):
            with contextlib.redirect_stderr(io.StringIO()) as output, self.assertRaises(SystemExit) as error:
                bbc.main(["--site", "https://example.com/"])
        self.assertEqual(error.exception.code, 2)
        self.assertIn("BBC_GEONEWS_USERNAME and BBC_GEONEWS_PASSWORD", output.getvalue())

    def test_bbc_publishing_uses_shared_reader_with_separate_settings(self):
        credentials = {"BBC_GEONEWS_USERNAME": "bbc", "BBC_GEONEWS_PASSWORD": "bbc-secret"}
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, credentials, clear=True):
            with patch.object(reader, "Client") as client, patch.object(reader, "Feed") as feed, \
                    patch.object(reader, "Publisher") as publisher, patch.object(reader, "process", return_value=True) as process:
                publisher.return_value.recheck_recent.return_value = True
                result = bbc.main(["--site", "https://example.com/", "--state", str(Path(directory) / "bbc.sqlite3")])
        self.assertEqual(result, 0)
        client.assert_called_once_with(user_agent="GeoNews-BBC-reader/1.0")
        feed.assert_called_once_with(client.return_value, "https://feeds.bbci.co.uk/news/world/rss.xml")
        publisher.assert_called_once_with(client.return_value, "https://example.com/", "bbc", "bbc-secret")
        publisher.return_value.login.assert_called_once()
        self.assertEqual(process.call_args.args[1].limit, 20)

    def test_help_has_separate_credentials(self):
        with contextlib.redirect_stdout(io.StringIO()) as output, self.assertRaises(SystemExit):
            bbc.main(["--help"])
        self.assertIn("BBC_GEONEWS_USERNAME", output.getvalue())


if __name__ == "__main__":
    unittest.main()
