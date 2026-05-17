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
hooks_src="${repo_root}/scripts/hooks"
hooks_dst="${repo_root}/.git/hooks"

for hook in pre-commit; do
    install -m 0755 "${hooks_src}/${hook}" "${hooks_dst}/${hook}"
    echo "installed ${hook} -> ${hooks_dst}/${hook}"
done
