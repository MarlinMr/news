"""Docker integration checks: python3 tests/frontend-assets.py IMAGE_TAG.

Build the image first. Containers have no network or database; HTTP requests run
on loopback inside them. Tests verify JS/CSS/images without runtime egress.
"""
import json
import base64
from html.parser import HTMLParser
import subprocess
import sys
import time


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()


def fetch(container, path=""):
    url = json.dumps("http://127.0.0.1/" + path)
    code = "$body = file_get_contents(" + url + "); if ($body === false) exit(1); echo base64_encode($body);"
    return base64.b64decode(docker("exec", container, "php", "-r", code))


class Dependencies(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "script" and "src" in attrs:
            self.urls.append(attrs["src"])
        if tag == "link" and attrs.get("rel") == "stylesheet":
            self.urls.append(attrs["href"])


def check_assets(image):
    containers = []
    try:
        for mode in (None, "local", "cdn"):
            args = ["run", "-d", "--network", "none"]
            if mode:
                args += ["-e", "FRONTEND_ASSETS=" + mode]
            container = docker(*args, image)
            containers.append(container)
            for attempt in range(60):
                try:
                    html = fetch(container).decode()
                    break
                except (OSError, subprocess.CalledProcessError):
                    if attempt == 59:
                        raise
                    time.sleep(0.1)
            dependencies = Dependencies()
            dependencies.feed(html)
            libraries = [url for url in dependencies.urls if "leaflet" in url or "MarkerCluster" in url]
            assert len(libraries) == 5, libraries
            if mode == "local":
                assert all(not url.startswith(("https:", "http:", "/")) for url in libraries), libraries
                images = ["layers.png", "layers-2x.png", "marker-icon.png", "marker-icon-2x.png", "marker-shadow.png"]
                for url in libraries + ["vendor/leaflet-1.9.4/dist/images/" + name for name in images]:
                    assert fetch(container, url), url
                # Switching back must restore the pristine CDN references, not re-rewrite local HTML.
                docker("exec", "-e", "FRONTEND_ASSETS=cdn", container, "php", "/usr/local/lib/geonews/configure-frontend-assets.php")
                assert "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" in fetch(container).decode()
            else:
                assert all(url.startswith("https://unpkg.com/") for url in libraries), libraries
        invalid = docker("run", "-d", "--network", "none", "-e", "FRONTEND_ASSETS=invalid", image)
        containers.append(invalid)
        result = subprocess.check_output(["docker", "wait", invalid], text=True, timeout=10).strip()
        logs = subprocess.check_output(["docker", "logs", invalid], stderr=subprocess.STDOUT, text=True)
        assert result != "0" and "FRONTEND_ASSETS must be" in logs, (result, logs)
        config = json.loads(docker("inspect", containers[0]))[0]
        assert config["State"]["Running"], "Default mode must keep Apache running."
        print("Frontend assets passed: default/CDN modes, offline local JS/CSS/images, mode reversal, invalid-mode rejection.")
    finally:
        for container in containers:
            docker("rm", "-f", "-v", container)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 tests/frontend-assets.py IMAGE_TAG")
    check_assets(sys.argv[1])
