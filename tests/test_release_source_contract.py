"""Guard production release resolution against staging test tags."""
from pathlib import Path
import unittest

WORKFLOW = (Path(__file__).resolve().parents[1] / ".github/workflows/production.yml").read_text(encoding="utf-8")

class ReleaseSourceContractTest(unittest.TestCase):
    def test_auto_tag_resolves_promoted_release_tag(self):
        self.assertIn("SOURCE_TAG: ${{ github.ref_type == 'tag' && github.ref_name ||", WORKFLOW)
        self.assertNotIn("format('staging-", WORKFLOW)
        self.assertNotIn('SOURCE_TAG" != "staging-', WORKFLOW)

    def test_promoted_tag_checked_against_master(self):
        self.assertIn("refs/tags/${SOURCE_TAG}:refs/tags/${SOURCE_TAG}", WORKFLOW)
        self.assertIn('merge-base --is-ancestor "$pinned_sha" origin/master', WORKFLOW)
        self.assertIn("source vX.Y.Z tag is not reachable", WORKFLOW)

    def test_manual_master_publish_remains_sha_pinned(self):
        self.assertIn('"$PUBLISH" == "true" && "$SOURCE_MODE" == "master"', WORKFLOW)
        self.assertIn('resolved_sha" != "$SOURCE_SHA"', WORKFLOW)

if __name__ == "__main__":
    unittest.main()
