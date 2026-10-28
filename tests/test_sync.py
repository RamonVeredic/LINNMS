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

    def test_idempotent_scheduling(self):
        self.assertIn("workflow_dispatch:", SYNC)
        self.assertIn("schedule:", SYNC)
        self.assertIn("git diff --cached --quiet", SYNC)
        self.assertIn("permissions:\n  contents: write", SYNC)
        self.assertIn("secrets.LINNMS_READ_TOKEN", SYNC)

if __name__ == "__main__":
    unittest.main()
