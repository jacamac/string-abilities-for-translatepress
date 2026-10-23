#!/usr/bin/env bash
#
# Fail if any tracked file contains a term from the private deny list.
#
# Terms (one per line) come from the SAT_PRIVATE_TERMS environment variable
# (CI secret) or, when it is unset, from the git-ignored .private-terms file
# (local pre-commit). Matching is literal and case-insensitive. Only the file
# and line number of a match are printed, never the term or the line.
#
# Exit codes: 0 clean or no deny list, 1 match found.

set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "$root"

terms="$(mktemp)"
trap 'rm -f "$terms"' EXIT

if [ -n "${SAT_PRIVATE_TERMS:-}" ]; then
	printf '%s\n' "$SAT_PRIVATE_TERMS" >"$terms"
elif [ -f .private-terms ]; then
	cat .private-terms >"$terms"
else
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		echo "::notice::SAT_PRIVATE_TERMS is not set; private-terms check skipped."
	else
		echo "No .private-terms file; private-terms check skipped."
	fi
	exit 0
fi

# Strip CR and surrounding blanks, drop empty lines: an empty pattern would
# match every line.
clean="$(mktemp)"
trap 'rm -f "$terms" "$clean"' EXIT
sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$terms" | grep -v '^$' >"$clean" || true

if [ ! -s "$clean" ]; then
	echo "Deny list is empty; private-terms check skipped."
	exit 0
fi

matches="$(
	git ls-files -z |
		xargs -0 grep --text --with-filename --line-number --ignore-case --fixed-strings --file="$clean" -- 2>/dev/null |
		cut -d: -f1-2 ||
		true
)"

if [ -n "$matches" ]; then
	echo "Private terms found (file:line):"
	printf '%s\n' "$matches"
	exit 1
fi

echo "Private-terms check passed ($(wc -l <"$clean" | tr -d ' ') terms)."
