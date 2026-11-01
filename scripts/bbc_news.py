#!/usr/bin/env python3
"""Watch BBC World RSS using separate BBC GeoNews credentials. Python 3.10+."""

import sys

from nrk_news import main as reader_main


def main(argv=None):
    return reader_main(
        argv, source="BBC", feed_url="https://feeds.bbci.co.uk/news/world/rss.xml",
        state_name="bbc-news", credential_prefix="BBC_GEONEWS",
        user_agent="GeoNews-BBC-reader/1.0",
    )


if __name__ == "__main__":
    sys.exit(main())
