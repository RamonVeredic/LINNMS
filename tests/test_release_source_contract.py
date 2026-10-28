"""Check LINNMS workflow source selection and publishing rules."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/linnms.yml").read_text(encoding="utf-8")
DOCKERFILE = (ROOT / "docker/Dockerfile").read_text(encoding="utf-8")

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

    def test_auto_publish_single_image(self):
        self.assertEqual(WORKFLOW.count("uses: docker/build-push-action@v6"), 1)
        self.assertIn("push: true", WORKFLOW)
        self.assertNotIn("push: false", WORKFLOW)
        self.assertNotIn("inputs.publish", WORKFLOW)
        self.assertNotIn("      publish:", WORKFLOW)
        self.assertNotIn("-candidate", WORKFLOW)
        self.assertNotIn("build-args:", WORKFLOW)
        self.assertNotIn("ARG VERSION", DOCKERFILE)
        self.assertNotIn("ARG SOURCE_REVISION", DOCKERFILE)
        self.assertIn("ghcr.io/ramonveredic/linnms:latest", WORKFLOW)

    def test_checked_out_commit_and_pq_scope(self):
        self.assertIn('resolved_sha="$(git -C "$work/source" rev-parse HEAD)"', WORKFLOW)
        self.assertIn('echo "sha=$resolved_sha" >> "$GITHUB_OUTPUT"', WORKFLOW)
        self.assertIn("org.opencontainers.image.version=${{ steps.release.outputs.version }}", WORKFLOW)
        self.assertIn("org.opencontainers.image.revision=${{ steps.source.outputs.sha }}", WORKFLOW)
        self.assertIn("LINNMS_PQ_KEY_FILE=/work/pq.key", WORKFLOW)
        self.assertIn("find /work/plain/config/app/runtime/vendor", WORKFLOW)
        self.assertNotIn("find /work/plain/config -type f", WORKFLOW)
        self.assertIn('cp docker/Dockerfile "$work/context/Dockerfile"', WORKFLOW)
        self.assertIn('cd "$RUNNER_TEMP/linnms/pq"', WORKFLOW)
        self.assertNotIn('linnms/protection', WORKFLOW)
        self.assertIn('"$work/context/pq"', WORKFLOW)
        self.assertIn("find /opt/linnms/runtime/vendor -type f", DOCKERFILE)
        self.assertNotIn("Unexpected encrypted application PHP", DOCKERFILE)
        self.assertNotIn("dashboard detail modal template missing", DOCKERFILE)

if __name__ == "__main__":
    unittest.main()
