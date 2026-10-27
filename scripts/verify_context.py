"""Reject any plaintext PHP or staging/development artifacts before Docker build."""
from __future__ import annotations
import pathlib
import sys

release_build_context = pathlib.Path(sys.argv[1]).resolve(strict=True)
if not (release_build_context / "bundle/config/www/index.php").is_file():
    raise SystemExit("Release web entrypoint missing from build context")
if not (release_build_context / "protection/loader/linnms_loader.c").is_file():
    raise SystemExit("Native loader source missing")
header = release_build_context / "protection/loader/linnms_auto_key.h"
if not header.is_file() or "#define LINNMS_EMBEDDED_KEY 1" not in header.read_text():
    raise SystemExit("Missing per-build embedded key header")
deny = {".git", ".github", "docker", "tests", "tools", "source-seed", "doc",
        "linnms-staging", "staging", "__pycache__"}
encrypted_php_file_count = 0
for file in release_build_context.rglob("*"):
    if file.is_symlink():
        raise SystemExit(f"build context: {file}")
    if not file.is_file():
        continue
    rel = file.relative_to(release_build_context)
    if rel.parts[0] not in {"bundle", "protection"} and rel.as_posix() != "Dockerfile":
        raise SystemExit(f"Unexpected build context item: {rel}")
    if rel.parts[0] == "protection" and rel.suffix not in {".c", ".h", ".m4"}:
        raise SystemExit(f"Unexpected protected-loader build input: {rel}")
    if rel.parts[0] == "bundle":
        if any(part.lower() in deny or part.startswith(".") for part in rel.parts):
            raise SystemExit(f"Staging/development artifact: {rel}")
        if rel.suffix == ".php":
            encrypted_php_file_count += 1
            if file.read_bytes()[:4] != b"LNC1":
                raise SystemExit(f"Plaintext or unrecognized PHP in release bundle: {rel}")
        if rel.name.startswith("Dockerfile") or rel.suffix.lower() in {".yml", ".yaml", ".sample"}:
            raise SystemExit(f"Build/staging artifact: {rel}")
if encrypted_php_file_count < 250:
    raise SystemExit(f"Unexpected encrypted PHP file count in release bundle: {encrypted_php_file_count}")
init = (release_build_context / "bundle/linnms.init.d/50-linnms-public-init").read_text(encoding="utf-8")
if any(s in init for s in ("SOURCE_MOUNTS", "SOURCE_SEED", "SOURCE_ROOT", "staging", "source-seed")):
    raise SystemExit("Production init still contains staging logic")
print(f"Release build context OK: {encrypted_php_file_count} authenticated encrypted PHP files")
