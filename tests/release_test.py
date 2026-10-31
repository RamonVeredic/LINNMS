import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
FILTER = ROOT / "scripts" / "release.py"
VERIFY = ROOT / "scripts" / "verify_context.py"

INIT = '''#!/usr/bin/with-contenv sh
set -eu
SOURCE_ROOT=/workspace/linnms-source
SOURCE_SEED=/opt/linnms/source-seed
SOURCE_MOUNTS="${LINNMS_SOURCE_MOUNTS:-0}"

if [ "$SOURCE_MOUNTS" = "1" ]; then
  echo staging-start
else
  CONFIG_TEMPLATE="$CONFIG_IMAGE"
  NGINX_TEMPLATE="$NGINX_IMAGE"
fi

if [ -L /config/nginx/log ]; then
  echo hello
fi
# Image Releases; hanya database.php
if [ "$SOURCE_MOUNTS" = "1" ]; then
  chown -R abc:users "$SOURCE_ROOT/config/www"
fi

echo release-ready
'''

class ReleaseFilterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name)
        self.stage = self.root / "staging"
        self.stage.mkdir()
        self.output = self.root / "plain"
        self._write("linnms.init.d/50-linnms-public-init", INIT)
        self._write("config/app/runtime/app/paths.php", "<?php echo 1;")
        self._write("config/app/runtime/backend/api.php", "<?php echo 1;")
        self._write("config/www/index.php", "<?php echo 1;")
        self._write("nginx/nginx.conf", "user abc;")
        self._write("nginx/site-confs/default.conf", "root /config/www;")
        self._write("docker/Dockerfile.staging", "FROM example")
        self._write(".github/workflows/build-staging.yml", "on: push")
        self._write("doc/notes.md", "for development only")
        self._write("linnms.service.d/linnms-reader-1", "#!/bin/sh\necho work")
        self._write("linnms.service.d/linnms-sampler-1", "#!/bin/sh\necho sampler")
        self._write("config/www/assets/pages/dashboard/assets.json", '{"styles":[],"scripts":[]}')
        for i in range(25):
            self._write(f"config/app/runtime/vendor/driver/example{i}.php", "<?php echo 1;")
        for i in range(255):
            self._write(f"config/app/runtime/app/example{i}.php", "<?php echo 1;")

    def _write(self, filename, data):
        file = self.stage / filename
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(data)

    def test_filters_staging_build_artifacts(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)],
                       check=True)
        self.assertFalse((self.output / "docker").exists())
        self.assertFalse((self.output / ".github").exists())
        self.assertFalse((self.output / "doc").exists())
        init = (self.output / "linnms.init.d/50-linnms-public-init").read_text()
        self.assertNotIn("SOURCE_MOUNTS", init)
        self.assertNotIn("staging", init)
        self.assertIn('CONFIG_TEMPLATE="$CONFIG_IMAGE"', init)
        self.assertIn("release-ready", init)
        self.assertTrue((self.output / "config/app/runtime/app/example1.php").exists())
        self.assertTrue((self.output / "config/www/assets/pages/dashboard/assets.json").exists())
        self.assertTrue((self.output / "linnms.service.d/linnms-sampler-1").exists())

    def test_staging_init_without_shebang(self):
        init_path = self.stage / 'linnms.init.d/50-linnms-public-init'
        init_path.write_text(INIT.removeprefix('#!/usr/bin/with-contenv sh\n'))
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)], check=True)
        converted = (self.output / 'linnms.init.d/50-linnms-public-init').read_text()
        self.assertTrue(converted.startswith('#!/usr/bin/with-contenv sh\nset -eu\n'))
        self.assertEqual(converted.count('#!/usr/bin/with-contenv sh'), 1)
        self.assertNotIn('SOURCE_MOUNTS', converted)
        self.assertNotIn('staging', converted.lower())

    def test_auditor_rejects_plaintext(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)],
                       check=True)
        context = self.root / "context"
        (context / "bundle").mkdir(parents=True)
        (context / "pq/loader").mkdir(parents=True)
        (context / "pq/loader/linnms_loader.c").write_text("/* build only */")
        (context / "pq/loader/linnms_auto_key.h").write_text("#define LINNMS_EMBEDDED_KEY 1")
        (context / "Dockerfile").write_text("FROM scratch")
        import shutil
        shutil.copytree(self.output, context / "bundle", dirs_exist_ok=True)
        for php in (context / "bundle/config/app/runtime/vendor").rglob("*.php"):
            php.write_bytes(b"LNC1" + bytes(64))
        subprocess.run([sys.executable, str(VERIFY), str(context)], check=True)
        unprotected = context / "bundle/config/www/index.php"
        self.assertEqual(unprotected.read_text(), "<?php echo 1;")
        protected = context / "bundle/config/app/runtime/vendor/driver/example1.php"
        protected.write_text("<?php echo 1;")
        result = subprocess.run([sys.executable, str(VERIFY), str(context)],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Plaintext PHP inside runtime/vendor", result.stderr + result.stdout)

if __name__ == "__main__":
    unittest.main()
