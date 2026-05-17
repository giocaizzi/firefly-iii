#!/usr/bin/env bash
#
# install-hooks.sh - install Firefly III local git hooks (WIP_MCP D-031).
#
# The pre-commit hook runs Mago format, Mago lint, PHPStan, and the unit-test
# suite before each commit. It cannot be tracked under .git/hooks itself, so
# this installer copies it from scripts/hooks/ on demand.
#
set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
git_common_dir=$(git rev-parse --git-common-dir)
git_dir=$(git rev-parse --git-dir)

hooks_src="${repo_root}/scripts/hooks"
# In a linked worktree, $git_dir resolves to .git/worktrees/<name>/, which has
# its own hooks/ that is empty by default — install there. In the main worktree,
# $git_dir == $git_common_dir, so we land on .git/hooks/ as expected.
hooks_dst="${git_dir}/hooks"

mkdir -p "${hooks_dst}"

for hook in pre-commit; do
    install -m 0755 "${hooks_src}/${hook}" "${hooks_dst}/${hook}"
    echo "installed ${hook} -> ${hooks_dst}/${hook}"
done
