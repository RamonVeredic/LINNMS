from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MASTER = (ROOT / ".github/workflows/master.yml").read_text(encoding="utf-8")
SOURCE_FILTER = (ROOT / "scripts/release.py").read_text(encoding="utf-8")

class MasterContractTest(unittest.TestCase):
    def test_vendor_is_excluded_everywhere(self):
        self.assertIn("find \"$export_root\" -type d -name vendor -prune -exec rm -rf", MASTER)
        self.assertIn("-type d -name vendor -print -quit", MASTER)
        self.assertIn("CONFIG_FILES = {", SOURCE_FILTER)
        self.assertNotIn('"config/app/config/database.php",', SOURCE_FILTER)

    def test_build_files_are_not_replaced(self):
        self.assertIn("for dir in config nginx linnms.init.d linnms.service.d", MASTER)
        self.assertIn("git add -A -- config nginx linnms.init.d linnms.service.d", MASTER)
        self.assertNotIn("git add -A .", MASTER)

    def test_subject_matches_source_master(self):
        self.assertIn('git -C "$work/source" log -1 --format=%s', MASTER)
        self.assertIn('git commit -m "$subject" -m "Source: LINNMS-Staging@${revision}"', MASTER)
        self.assertNotIn('git commit -m "sync: source', MASTER)

    def test_manual_or_master_dispatch(self):
        self.assertIn("name: master", MASTER)
        self.assertIn("run-name: master", MASTER)
        self.assertIn("jobs:\n  master:\n    name: master", MASTER)
        self.assertIn("workflow_dispatch:", MASTER)
        self.assertIn("repository_dispatch:", MASTER)
        self.assertIn("types: [master]", MASTER)
        self.assertNotIn("schedule:", MASTER)
        self.assertNotIn("cron:", MASTER)
        self.assertNotIn("branches: [main]", MASTER)
        self.assertIn("github.event.client_payload.sha", MASTER)
        self.assertIn('git -C "$work/source" merge-base --is-ancestor "$SOURCE_SHA" origin/master', MASTER)
        self.assertIn("git diff --cached --quiet", MASTER)
        self.assertIn("permissions:\n  contents: write", MASTER)
        self.assertIn("secrets.LINNMS_READ_TOKEN", MASTER)

if __name__ == "__main__":
    unittest.main()
