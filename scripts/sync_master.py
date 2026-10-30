"""Mirror staging application trees to LINNMS without vendor or private DB credentials."""
from __future__ import annotations

import pathlib
import shutil
import sys

from release import init

TREES = ("config", "nginx", "linnms.init.d", "linnms.service.d")
EXCLUDE_NAMES = {".git", ".github", "__pycache__", "vendor"}
EXCLUDE_FILES = {"config/app/config/database.php"}


def mirror(source: pathlib.Path, destination: pathlib.Path) -> None:
    source = source.resolve(strict=True)
    destination = destination.resolve()
    if destination.exists() or destination.is_relative_to(source) or source.is_relative_to(destination):
        raise ValueError("Refusing existing or overlapping export directory")
    if any(not (source / tree).is_dir() for tree in TREES):
        missing = [tree for tree in TREES if not (source / tree).is_dir()]
        raise ValueError("Missing source directories: " + ", ".join(missing))
    destination.mkdir(parents=True, exist_ok=False)
    for tree in TREES:
        (destination / tree).mkdir(parents=True, exist_ok=True)
        for path in (source / tree).rglob("*"):
            rel = path.relative_to(source)
            if any(part in EXCLUDE_NAMES or part.startswith(".") for part in rel.parts):
                continue
            if rel.as_posix() in EXCLUDE_FILES:
                continue
            if path.is_symlink():
                raise ValueError(f"Symlinks not permitted in synchronized files: {rel}")
            output = destination / rel
            if path.is_dir():
                output.mkdir(parents=True, exist_ok=True)
            elif path.is_file():
                output.parent.mkdir(parents=True, exist_ok=True)
                if rel.as_posix() == "linnms.init.d/50-linnms-public-init":
                    output.write_text(init(path.read_text(encoding="utf-8")), encoding="utf-8")
                else:
                    shutil.copy2(path, output)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: sync_master.py STAGING EXPORT")
    mirror(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]))
