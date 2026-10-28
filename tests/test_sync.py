from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SYNC = (ROOT / ".github/workflows/sync.yml").read_text(encoding="utf-8")
SOURCE_FILTER = (ROOT / "scripts/release.py").read_text(encoding="utf-8")

class SyncContractTest(unittest.TestCase):
    def test_vendor_is_excluded_everywhere(self):
        self.assertIn("find \"$export_root\" -type d -name vendor -prune -exec rm -rf", SYNC)
        self.assertIn("-type d -name vendor -print -quit", SYNC)
        self.assertIn("CONFIG_FILES = {", SOURCE_FILTER)
        self.assertNotIn('"config/app/config/database.php",', SOURCE_FILTER)

    def test_build_files_are_not_replaced(self):
        self.assertIn("for dir in config nginx linnms.init.d linnms.service.d", SYNC)
        self.assertIn("git add -A -- config nginx linnms.init.d linnms.service.d", SYNC)
        self.assertNotIn("git add -A .", SYNC)

    def test_subject_matches_source_master(self):
        self.assertIn('git -C "$work/source" log -1 --format=%s', SYNC)
        self.assertIn('git commit -m "$subject" -m "Source: LINNMS-Staging@${revision}"', SYNC)
        self.assertNotIn('git commit -m "sync: source', SYNC)

    def test_manual_or_master_dispatch(self):
        self.assertIn("workflow_dispatch:", SYNC)
        self.assertIn("repository_dispatch:", SYNC)
        self.assertIn("types: [linnms-sync]", SYNC)
        self.assertNotIn("schedule:", SYNC)
        self.assertNotIn("cron:", SYNC)
        self.assertNotIn("branches: [main]", SYNC)
        self.assertIn("github.event.client_payload.sha", SYNC)
        self.assertIn('git -C "$work/source" merge-base --is-ancestor "$SOURCE_SHA" origin/master', SYNC)
        self.assertIn("git diff --cached --quiet", SYNC)
        self.assertIn("permissions:\n  contents: write", SYNC)
        self.assertIn("secrets.LINNMS_READ_TOKEN", SYNC)

if __name__ == "__main__":
    unittest.main()
