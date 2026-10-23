#!/usr/bin/env bash
#
# Fail unless the plugin header Version, SAT_VERSION and the readme.txt
# Stable tag are identical, and equal to the expected version if one is given.
#
# Usage: bin/check-version.sh [expected-version]

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

header="$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' string-abilities-for-translatepress.php)"
constant="$(sed -n "s/^define( 'SAT_VERSION', '\([^']*\)' );$/\1/p" string-abilities-for-translatepress.php)"
stable="$(sed -n 's/^Stable tag: *\([^ ]*\) *$/\1/p' readme.txt)"
expected="${1:-$header}"

echo "Version header: ${header:-missing}, SAT_VERSION: ${constant:-missing}, Stable tag: ${stable:-missing}, expected: ${expected:-missing}"

if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$stable" ] || [ "$header" != "$expected" ]; then
	echo "Version mismatch."
	exit 1
fi
