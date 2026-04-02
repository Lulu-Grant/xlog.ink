import json
import tempfile
import unittest
from pathlib import Path

import build_recent


class BuildRecentTests(unittest.TestCase):
    def test_load_from_index_deduplicates_and_keeps_first_seen_slug(self):
        with tempfile.TemporaryDirectory() as tmp:
            index_file = Path(tmp) / "pages.jsonl"
            lines = [
                {"slug": "bbb222", "title": "Older B", "time": "2026-04-01T00:00:00+00:00"},
                {"slug": "aaa111", "title": "A entry", "time": "2026-04-03T00:00:00+00:00"},
                {"slug": "bbb222", "title": "Newer B but duplicate", "time": "2026-04-04T00:00:00+00:00"},
            ]
            index_file.write_text("\n".join(json.dumps(line) for line in lines), encoding="utf-8")

            items = build_recent.load_from_index(index_file)

            self.assertEqual(2, len(items))
            self.assertEqual("Older B", items[0]["title"])
            self.assertEqual("https://bbb222.xlog.ink/", items[0]["url"])

    def test_render_recent_html_escapes_titles(self):
        html = build_recent.render_recent_html([
            {"title": '<Unsafe "Title">', "url": "https://safe.example/", "time": "2026-04-02T12:00:00+00:00"}
        ])

        self.assertIn("&lt;Unsafe &quot;Title&quot;&gt;", html)
        self.assertIn('title="2026-04-02T12:00:00+00:00"', html)

    def test_build_recent_uses_index_and_sorts_descending(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            index_file = root / "pages.jsonl"
            site_dir = root / "site"
            output_file = root / "recent.html"
            site_dir.mkdir()

            lines = [
                {"slug": "older00", "title": "Older", "time": "2026-04-01T00:00:00+00:00"},
                {"slug": "newer00", "title": "Newer", "time": "2026-04-05T00:00:00+00:00"},
            ]
            index_file.write_text("\n".join(json.dumps(line) for line in lines), encoding="utf-8")

            build_recent.build_recent(index_file=index_file, site_dir=site_dir, output_file=output_file, max_items=10)

            html = output_file.read_text(encoding="utf-8")
            self.assertLess(html.index("Newer"), html.index("Older"))


if __name__ == "__main__":
    unittest.main()
