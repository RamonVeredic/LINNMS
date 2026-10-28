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

    def test_manual_master_publish_needs_no_sha_input(self):
        self.assertNotIn("source_sha:", WORKFLOW)
        self.assertNotIn("SOURCE_SHA:", WORKFLOW)
        self.assertNotIn('requires source_sha', WORKFLOW)
        self.assertNotIn('resolved_sha" != "$SOURCE_SHA"', WORKFLOW)

    def test_checked_out_commit_still_recorded_for_traceability(self):
        self.assertIn('resolved_sha="$(git -C "$work/staging" rev-parse HEAD)"', WORKFLOW)
        self.assertIn('echo "sha=$resolved_sha" >> "$GITHUB_OUTPUT"', WORKFLOW)
        self.assertIn("SOURCE_REVISION=${{ steps.source.outputs.sha }}", WORKFLOW)

if __name__ == "__main__":
    unittest.main()
