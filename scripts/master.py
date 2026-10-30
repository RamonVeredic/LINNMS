"""Mirror staging application trees to LINNMS without vendor or private DB credentials."""
from __future__ import annotations

import pathlib
import shutil
import re
import sys

def init(text: str) -> str:
    """Remove"""
    start_marker = 'if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
    start = text.find(start_marker)
    else_marker = '\nelse\n  CONFIG_TEMPLATE="$CONFIG_IMAGE"'
    branch_start = text.find(else_marker, start)
    end_marker = '\nfi\n\nif [ -L /config/nginx/log ]; then'
    branch_end = text.find(end_marker, branch_start)
    if start == -1 or branch_start == -1 or branch_end == -1:
        raise ValueError("Unknown init structure; refusing unsafe conversion")
    branch = text[branch_start + len('\nelse\n'):branch_end]
    text = text[:start] + branch + text[branch_end + len('\nfi\n'):]
    text = re.sub(r'^SOURCE_ROOT=.*\n|^SOURCE_SEED=.*\n|^SOURCE_MOUNTS=.*\n',
                  '', text, flags=re.MULTILINE)
    chown_block = ('if [ "$SOURCE_MOUNTS" = "1" ]; then\n'
                   '  chown -R abc:users "$SOURCE_ROOT/config/www"\n'
                   'fi\n\n')
    if text.count(chown_block) != 1:
        raise ValueError("remove block")
    text = text.replace(chown_block, '')
    rejected = ("SOURCE_MOUNTS", "SOURCE_ROOT", "SOURCE_SEED",
                "source-seed", "Dockerfile.staging", "LINNMS_SOURCE_MOUNTS",
                "staging", "/workspace/linnms-source")
    if any(term.lower() in text.lower() for term in rejected):
        raise ValueError("Staging-only init logic remains")
    if not text.startswith('#!/usr/bin/with-contenv sh'):
        raise ValueError("Invalid init script")
    return text

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
        raise SystemExit("Usage: master.py STAGING EXPORT")
    mirror(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]))
