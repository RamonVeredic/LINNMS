import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
FILTER = ROOT / "scripts" / "filter_production.py"
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
# staging atau ke image production; hanya database.php
if [ "$SOURCE_MOUNTS" = "1" ]; then
  chown -R abc:users "$SOURCE_ROOT/config/www"
fi

echo production-ready
'''

class ProductionFilterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name)
        self.stage = self.root / "staging"
        self.stage.mkdir()
        self.output = self.root / "plain"
        self._write("linnms.init.d/50-linnms-public-init", INIT)
        self._write("config/app/config/public-entry.php", "<?php echo 1;")
        self._write("config/app/runtime/app/paths.php", "<?php echo 1;")
        self._write("config/app/runtime/app/runtime.php", "<?php echo 1;")
        self._write("config/app/runtime/backend/api.php", "<?php echo 1;")
        self._write("config/www/index.php", "<?php echo 1;")
        self._write("nginx/nginx.conf", "user abc;")
        self._write("nginx/site-confs/default.conf", "root /config/www;")
        self._write("docker/Dockerfile.staging", "FROM example")
        self._write(".github/workflows/build-staging.yml", "on: push")
        self._write("doc/notes.md", "for development only")
        self._write("linnms.service.d/linnms-reader-1", "#!/bin/sh\necho work")
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
        self.assertIn("production-ready", init)
        self.assertTrue((self.output / "config/app/runtime/app/example1.php").exists())

    def test_auditor_rejects_plaintext(self):
        subprocess.run([sys.executable, str(FILTER), str(self.stage), str(self.output)],
                       check=True)
        context = self.root / "context"
        (context / "bundle").mkdir(parents=True)
        (context / "protection/loader").mkdir(parents=True)
        (context / "protection/loader/linnms_loader.c").write_text("/* build only */")
        (context / "Dockerfile").write_text("FROM scratch")
        import shutil
        shutil.copytree(self.output, context / "bundle", dirs_exist_ok=True)
        for php in (context / "bundle").rglob("*.php"):
            php.write_bytes(b"LNC1" + bytes(64))
        subprocess.run([sys.executable, str(VERIFY), str(context)], check=True)
        (context / "bundle/config/www/index.php").write_text("<?php source")
        result = subprocess.run([sys.executable, str(VERIFY), str(context)],
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Plaintext", result.stderr + result.stdout)

if __name__ == "__main__":
    unittest.main()
