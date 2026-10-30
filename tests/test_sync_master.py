from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "master.py"


class MasterMirrorTests(unittest.TestCase):
    def test_mirrors_any_file_and_excludes_vendor(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "source"
            for directory in ("config", "nginx", "linnms.init.d", "linnms.service.d"):
                (source / directory).mkdir(parents=True)
            (source / "config/new-file.unknown").write_text("source")
            (source / "config/www/vendor").mkdir(parents=True)
            (source / "config/www/vendor/secret.php").write_text("vendor")
            (source / "config/app/config").mkdir(parents=True)
            (source / "config/app/config/database.php").write_text("secret")
            output = root / "out"
            subprocess.run([sys.executable, str(SCRIPT), str(source), str(output)], check=True)
            self.assertEqual((output / "config/new-file.unknown").read_text(), "source")
            self.assertFalse((output / "config/www/vendor").exists())
            self.assertFalse((output / "config/app/config/database.php").exists())
            self.assertTrue((output / "linnms.init.d").is_dir())


if __name__ == "__main__":
    unittest.main()
