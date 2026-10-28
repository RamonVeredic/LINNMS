"""Check the vendor payload and exclude development artifacts before image build."""
from __future__ import annotations
import pathlib
import sys

release_build_context = pathlib.Path(sys.argv[1]).resolve(strict=True)
if not (release_build_context / "bundle/config/www/index.php").is_file():
    raise SystemExit("Release web entrypoint missing from build context")
if not (release_build_context / "pq/loader/linnms_loader.c").is_file():
    raise SystemExit("Native loader source missing")
header = release_build_context / "pq/loader/linnms_auto_key.h"
if not header.is_file() or "#define LINNMS_EMBEDDED_KEY 1" not in header.read_text():
    raise SystemExit("Missing per-build embedded key header")
deny = {".git", ".github", "docker", "tests", "tools", "source-seed", "doc",
        "linnms-staging", "staging", "__pycache__"}
vendor_root = pathlib.Path("bundle/config/app/runtime/vendor")
if not (release_build_context / vendor_root).is_dir():
    raise SystemExit("PQ runtime/vendor tree missing")
encrypted_vendor_php_file_count = 0
for file in release_build_context.rglob("*"):
    if file.is_symlink():
        raise SystemExit(f"build context: {file}")
    if not file.is_file():
        continue
    rel = file.relative_to(release_build_context)
    if rel.parts[0] not in {"bundle", "pq"} and rel.as_posix() != "Dockerfile":
        raise SystemExit(f"Unexpected build context item: {rel}")
    if rel.parts[0] == "pq" and rel.suffix not in {".c", ".h", ".m4"}:
        raise SystemExit(f"Unexpected PQ build input: {rel}")
    if rel.parts[0] == "bundle":
        if any(part.lower() in deny or part.startswith(".") for part in rel.parts):
            raise SystemExit(f"Staging/development artifact: {rel}")
        if rel.suffix == ".php" and rel.is_relative_to(vendor_root):
            encrypted_vendor_php_file_count += 1
            with file.open("rb") as source_php:
                if source_php.read(4) != b"LNC1":
                    raise SystemExit(f"Plaintext PHP inside runtime/vendor: {rel}")
        if rel.name.startswith("Dockerfile") or rel.suffix.lower() in {".yml", ".yaml", ".sample"}:
            raise SystemExit(f"Build/staging artifact: {rel}")
if encrypted_vendor_php_file_count < 20:
    raise SystemExit(f"Unexpected PQ vendor PHP file count: {encrypted_vendor_php_file_count}")
init = (release_build_context / "bundle/linnms.init.d/50-linnms-public-init").read_text(encoding="utf-8")
if any(s in init for s in ("SOURCE_MOUNTS", "SOURCE_SEED", "SOURCE_ROOT", "staging", "source-seed")):
    raise SystemExit("Release init still contains staging logic")
print(f"Release bundle OK: {encrypted_vendor_php_file_count} vendor PHP files verified")
