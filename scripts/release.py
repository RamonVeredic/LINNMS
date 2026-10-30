"""release-only bundle"""
from __future__ import annotations
import argparse
import pathlib
import re
import shutil
import sys
RUNTIME_DIRECTORY = "config/app/runtime"
FRONTEND_DIRECTORY = "config/www"
NGINX_FILES = {
    "nginx/nginx.conf", "nginx/resolver.conf", "nginx/ssl.conf",
    "nginx/worker_processes.conf", "nginx/php/php-local.ini",
    "nginx/php/www2.conf", "nginx/site-confs/default.conf",
}
CONFIG_FILES = {
    "config/app/config/monitoring-drivers.php",
    "config/app/config/service.php",
    "config/app/config/session.php",
    "config/app/config/whatsapp-gateways.php",
}
SERVICES = {
    "linnms-access-refresh", "linnms-cleaner", "linnms-cli-broker",
    "linnms-courier-1", "linnms-initial-reader", "linnms-notifier",
    "linnms-reader-1", "linnms-reader-2", "linnms-reader-3",
    "linnms-sampler-1", "linnms-writer-1",
}
WEB_SUFFIXES = {".php", ".html", ".css", ".js", ".ico", ".png", ".jpg", ".jpeg", ".svg", ".woff", ".woff2", ".webp", ".gif", ".json"}
RUNTIME_SUFFIXES = {".php", ".json"}
FORBIDDEN_COMPONENTS = {".git", ".github", "docker", "doc", "tests", "test", "tools", "source-seed", "__pycache__"}
FORBIDDEN_SUFFIXES = {".sample", ".bak", ".orig", ".log", ".tmp", ".temp"}
def permitted(rel: pathlib.PurePosixPath) -> bool:
    name = rel.name
    s = rel.as_posix()
    if any(part.startswith(".") or part.lower() in FORBIDDEN_COMPONENTS for part in rel.parts):
        return False
    if any(name.lower().endswith(x) for x in FORBIDDEN_SUFFIXES):
        return False
    if s in NGINX_FILES or s in CONFIG_FILES:
        return True
    if len(rel.parts) == 2 and rel.parts[0] == "linnms.service.d" and name in SERVICES:
        return True
    if s == "linnms.init.d/50-linnms-public-init":
        return True
    if s.startswith(RUNTIME_DIRECTORY + "/") and rel.suffix.lower() in RUNTIME_SUFFIXES:
        return True
    if s.startswith(FRONTEND_DIRECTORY + "/") and rel.suffix.lower() in WEB_SUFFIXES:
        return True
    return False

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

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("staging", type=pathlib.Path)
    parser.add_argument("output", type=pathlib.Path)
    args = parser.parse_args()
    source = args.staging.resolve(strict=True)
    dest = args.output.resolve()
    if dest.exists() or dest.is_relative_to(source) or source.is_relative_to(dest):
        raise SystemExit("Refusing existing or nested output tree")
    dest.mkdir(parents=True, mode=0o700)
    file_count = 0
    for path in source.rglob("*"):
        rel = pathlib.PurePosixPath(path.relative_to(source).as_posix())
        if path.is_symlink():
            if permitted(rel):
                raise SystemExit(f"Symlink in allowed bundle: {rel}")
            continue
        if path.is_file() and permitted(rel):
            target = dest.joinpath(*rel.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            if rel.as_posix() == "linnms.init.d/50-linnms-public-init":
                target.write_text(init(path.read_text(encoding="utf-8")), encoding="utf-8")
            else:
                shutil.copyfile(path, target)
            if rel.parts[0] in {"linnms.init.d", "linnms.service.d"}:
                target.chmod(0o755)
            file_count += 1
    print(f"Filtered {file_count}")
    assert file_count > 250, " refusing build"

if __name__ == "__main__":
    main()
