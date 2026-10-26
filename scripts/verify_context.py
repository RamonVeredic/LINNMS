#!/usr/bin/env python3
"""Reject any plaintext PHP or staging/development artifacts before Docker build."""
from __future__ import annotations
import pathlib
import sys

root = pathlib.Path(sys.argv[1]).resolve(strict=True)
if not (root / "bundle/config/www/index.php").is_file():
    raise SystemExit("Production entrypoint missing")
if not (root / "protection/loader/linnms_loader.c").is_file():
    raise SystemExit("Native loader source missing")
deny = {".git", ".github", "docker", "tests", "tools", "source-seed", "doc",
        "linnms-staging", "staging", "__pycache__"}
php_count = 0
for file in root.rglob("*"):
    if file.is_symlink():
        raise SystemExit(f"Symlink in build context: {file}")
    if not file.is_file():
        continue
    rel = file.relative_to(root)
    # Dockerfile and loader source belong to the build context, not the final image.
    if rel.parts[0] not in {"bundle", "protection"} and rel.as_posix() != "Dockerfile":
        raise SystemExit(f"Unexpected build context item: {rel}")
    if rel.parts[0] == "bundle":
        if any(part.lower() in deny or part.startswith(".") for part in rel.parts):
            raise SystemExit(f"Staging/development artifact: {rel}")
        if rel.suffix == ".php":
            php_count += 1
            if file.read_bytes()[:4] != b"LNC1":
                raise SystemExit(f"Plaintext/unrecognized PHP in production: {rel}")
        if rel.name.startswith("Dockerfile") or rel.suffix.lower() in {".yml", ".yaml", ".sample"}:
            raise SystemExit(f"Build/staging artifact: {rel}")
if php_count < 250:
    raise SystemExit(f"Unexpected encrypted PHP file count: {php_count}")
init = (root / "bundle/linnms.init.d/50-linnms-public-init").read_text(encoding="utf-8")
if any(s in init for s in ("SOURCE_MOUNTS", "SOURCE_SEED", "SOURCE_ROOT", "staging", "source-seed")):
    raise SystemExit("Production init still contains staging logic")
print(f"Production context OK: {php_count} authenticated ciphertext PHP files")
