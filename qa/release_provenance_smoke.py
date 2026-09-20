#!/usr/bin/env python3
from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path


def git(root: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['git', '-C', str(root), *args],
        check=check,
        text=True,
        capture_output=True,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description='Validate MeteoNexa release/source Git provenance.')
    parser.add_argument('root', nargs='?', default=Path(__file__).resolve().parents[1])
    parser.add_argument('--require-git', action='store_true', help='Fail unless ROOT itself is the Git top-level.')
    parser.add_argument('--require-clean', action='store_true', help='Fail if tracked/untracked non-ignored files are dirty.')
    args = parser.parse_args()

    root = Path(args.root).resolve()
    if not (root / 'readme.md').is_file() or not (root / 'package.json').is_file():
        print('Release provenance FAIL: root does not look like MeteoNexa source.', file=sys.stderr)
        return 1

    if shutil.which('git') is None:
        if args.require_git:
            print('Release provenance FAIL: git is required but unavailable.', file=sys.stderr)
            return 1
        print('Release provenance PASS: Git unavailable; source-bundle mode.')
        return 0

    probe = git(root, 'rev-parse', '--show-toplevel', check=False)
    if probe.returncode != 0:
        if args.require_git:
            print('Release provenance FAIL: ROOT is not a Git repository.', file=sys.stderr)
            return 1
        print('Release provenance PASS: VCS-neutral source bundle (no Git repository at ROOT).')
        return 0

    top = Path(probe.stdout.strip()).resolve()
    if top != root:
        if args.require_git:
            print(f'Release provenance FAIL: Git top-level is {top}, expected {root}.', file=sys.stderr)
            return 1
        print(f'Release provenance PASS: parent Git repository ignored ({top}); package is treated as a source bundle.')
        return 0

    head = git(root, 'rev-parse', '--verify', 'HEAD', check=False)
    if head.returncode != 0 or not head.stdout.strip():
        print('Release provenance FAIL: repository exists at ROOT but has no committed HEAD.', file=sys.stderr)
        return 1

    if args.require_clean:
        status = git(root, 'status', '--porcelain', '--untracked-files=all').stdout.strip()
        if status:
            print('Release provenance FAIL: repository working tree is not clean:', file=sys.stderr)
            print(status, file=sys.stderr)
            return 1

    sha = head.stdout.strip()
    print(f'Release provenance PASS: Git root matches package root; HEAD={sha}.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
