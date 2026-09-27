#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import subprocess
import sys
from pathlib import PurePosixPath

DEPLOY_PREFIXES = (
    'api/',
    'assets/',
    'cron/',
    'customer/',
    'database/',
    'inc/',
)
SPECIAL_ALLOWED = {
    '.htaccess',
    'customer/uploads/.htaccess',
    'storage/logs/.htaccess',
}
PROTECTED_PREFIXES = (
    'customer/uploads/',
    'uploads/',
    'storage/logs/',
    'storage/cache/',
    'storage/runtime/',
    'storage/sessions/',
    'tmp/',
    'logs/',
)
PROTECTED_EXACT = {
    'config.php',
    'config.local.php',
    '.env',
}
SKIP_PREFIXES = (
    '.git/',
    '.github/',
    'tests/',
    'scripts/',
    'evotor-app/',
)


def safe_relative(path: str) -> bool:
    if not path or '\x00' in path or '\n' in path or '\r' in path:
        return False
    p = PurePosixPath(path)
    if p.is_absolute() or any(part in ('', '.', '..') for part in p.parts):
        return False
    return True


def deployable(path: str) -> bool:
    if not safe_relative(path):
        return False
    if path in SPECIAL_ALLOWED:
        return True
    if path in PROTECTED_EXACT or path.startswith('.env.'):
        return False
    if path in ('config.example.php', '.gitignore', 'README.md'):
        return False
    if any(path.startswith(prefix) for prefix in SKIP_PREFIXES):
        return False
    if any(path.startswith(prefix) for prefix in PROTECTED_PREFIXES):
        return False
    if '/' not in path and path.endswith('.php'):
        return True
    return any(path.startswith(prefix) for prefix in DEPLOY_PREFIXES)


def git_diff_records(base: str, head: str) -> list[tuple[str, list[str]]]:
    raw = subprocess.check_output([
        'git', 'diff', '--name-status', '-z', '-M', base, head,
    ])
    parts = raw.split(b'\x00')
    records: list[tuple[str, list[str]]] = []
    i = 0
    while i < len(parts) and parts[i]:
        status = parts[i].decode('utf-8')
        i += 1
        kind = status[:1]
        count = 2 if kind in ('R', 'C') else 1
        if i + count > len(parts):
            raise RuntimeError('Unexpected git diff output')
        paths = [parts[i + n].decode('utf-8') for n in range(count)]
        i += count
        records.append((status, paths))
    return records


def build_manifest(base: str, head: str) -> tuple[list[str], list[str], list[str]]:
    uploads: set[str] = set()
    deletes: set[str] = set()
    skipped: set[str] = set()

    for status, paths in git_diff_records(base, head):
        kind = status[:1]
        if kind in ('R', 'C'):
            old, new = paths
            if kind == 'R' and deployable(old):
                deletes.add(old)
            elif kind == 'R':
                skipped.add(old)
            if deployable(new):
                uploads.add(new)
            else:
                skipped.add(new)
            continue

        path = paths[0]
        if not deployable(path):
            skipped.add(path)
            continue
        if kind == 'D':
            deletes.add(path)
        else:
            uploads.add(path)

    uploads.difference_update(deletes)
    return sorted(uploads), sorted(deletes), sorted(skipped)


def write_nul(path: str, items: list[str]) -> None:
    with open(path, 'wb') as fh:
        for item in items:
            fh.write(item.encode('utf-8') + b'\x00')


def write_summary(path: str, base: str, head: str, uploads: list[str], deletes: list[str], skipped: list[str]) -> None:
    lines = [
        '## Beget delta manifest',
        '',
        f'- Base: `{base}`',
        f'- Head: `{head}`',
        f'- Upload/update: **{len(uploads)}**',
        f'- Delete: **{len(deletes)}**',
        f'- Skipped by safety policy: **{len(skipped)}**',
        '',
    ]
    if uploads:
        lines += ['### Upload/update', ''] + [f'- `{p}`' for p in uploads[:200]] + ['']
    if deletes:
        lines += ['### Delete', ''] + [f'- `{p}`' for p in deletes[:200]] + ['']
    if skipped:
        lines += ['### Skipped', ''] + [f'- `{p}`' for p in skipped[:200]] + ['']
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(lines))


def self_test() -> int:
    expected_true = [
        '.htaccess', 'index.php', 'api/customer_order.php', 'inc/bootstrap.php',
        'customer/assets/app.js', 'database/migrations/044_test.sql',
        'customer/uploads/.htaccess', 'storage/logs/.htaccess',
    ]
    expected_false = [
        'config.php', 'config.example.php', '.env', '.env.production',
        '.github/workflows/deploy.yml', 'tests/example.php', 'scripts/x.py',
        'evotor-app/app/build.gradle', 'customer/uploads/avatar.jpg',
        'storage/logs/runtime.jsonl', '../escape.php', '/tmp/evil.php',
        'README.md', '.gitignore',
    ]
    failures = [p for p in expected_true if not deployable(p)]
    failures += [p for p in expected_false if deployable(p)]
    if failures:
        print('Self-test failed:', ', '.join(failures), file=sys.stderr)
        return 1
    print('Beget delta manifest self-test passed')
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description='Build safe changed-file manifests for Beget deployment')
    parser.add_argument('--base')
    parser.add_argument('--head')
    parser.add_argument('--upload-output')
    parser.add_argument('--delete-output')
    parser.add_argument('--summary-output')
    parser.add_argument('--github-output')
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()

    if args.self_test:
        return self_test()
    required = [args.base, args.head, args.upload_output, args.delete_output, args.summary_output]
    if any(v is None for v in required):
        parser.error('base, head and output paths are required unless --self-test is used')

    uploads, deletes, skipped = build_manifest(args.base, args.head)
    for item in uploads:
        if not os.path.isfile(item) or os.path.islink(item):
            raise RuntimeError(f'Upload path is not a regular checked-out file: {item}')
    write_nul(args.upload_output, uploads)
    write_nul(args.delete_output, deletes)
    write_summary(args.summary_output, args.base, args.head, uploads, deletes, skipped)

    print(f'Upload/update: {len(uploads)}; delete: {len(deletes)}; skipped: {len(skipped)}')
    if args.github_output:
        with open(args.github_output, 'a', encoding='utf-8') as fh:
            fh.write(f'upload_count={len(uploads)}\n')
            fh.write(f'delete_count={len(deletes)}\n')
            fh.write(f'has_changes={"true" if uploads or deletes else "false"}\n')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
