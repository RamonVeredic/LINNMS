"""Check image build."""
from __future__ import annotations
import pathlib
import sys

build_context = pathlib.Path(sys.argv[1]).resolve(strict=True)
if not (build_context / "bundle/config/www/index.php").is_file():
    raise SystemExit("entrypoint missing")
if not (build_context / "pq/loader/linnms_loader.c").is_file():
    raise SystemExit("Loader source missing")
header = build_context / "pq/loader/linnms_auto.h"
if not header.is_file() or "#define LINNMS_EMBEDDED 1" not in header.read_text():
    raise SystemExit("Missing per-build embedded header")
deny = {".git", ".github", "docker", "tests", "tools", "source-seed", "doc",
        "linnms-staging", "staging", "__pycache__"}
target_file = pathlib.Path("bundle/config/app/runtime/vendor")
if not (build_context / target_file).is_dir():
    raise SystemExit("target tree missing")
file_count = 0
for file in build_context.rglob("*"):
    if file.is_symlink():
        raise SystemExit(f"build context: {file}")
    if not file.is_file():
        continue
    rel = file.relative_to(build_context)
    if rel.parts[0] not in {"bundle", "pq"} and rel.as_posix() != "Dockerfile":
        raise SystemExit(f"{rel}")
    if rel.parts[0] == "pq" and rel.suffix not in {".c", ".h", ".m4"}:
        raise SystemExit(f"Unexpected PQ build input: {rel}")
    if rel.parts[0] == "bundle":
        if any(part.lower() in deny or part.startswith(".") for part in rel.parts):
            raise SystemExit(f"Staging/development artifact: {rel}")
        if rel.suffix == ".php" and rel.is_relative_to(target_file):
            file_count += 1
            with file.open("rb") as source_php:
                if source_php.read(4) != b"LNC1":
                    raise SystemExit(f"{rel}")
        if rel.name.startswith("Dockerfile") or rel.suffix.lower() in {".yml", ".yaml", ".sample"}:
            raise SystemExit(f"{rel}")
if file_count < 20:
    raise SystemExit(f"{file_count}")
init = (build_context / "bundle/linnms.init.d/50-linnms-public-init").read_text(encoding="utf-8")
if any(s in init for s in ("SOURCE_MOUNTS", "SOURCE_SEED", "SOURCE_ROOT", "staging", "source-seed")):
    raise SystemExit("init")
print(f"Bundle OK: {file_count} Success")
