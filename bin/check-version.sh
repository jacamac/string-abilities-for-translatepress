#!/usr/bin/env bash
#
# Fail unless the plugin header Version, SAT_VERSION and the readme.txt
# Stable tag are identical, and equal to the expected version if one is given,
# and unless every file in src/ is require_once'd by the main plugin file
# (PHPUnit and PHPStan load classes differently from WordPress, so a missing
# require passes them and fails only on a site).
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

missing=0
for file in src/*.php; do
	if ! grep -qF "require_once __DIR__ . '/${file}';" string-abilities-for-translatepress.php; then
		echo "Not loaded by string-abilities-for-translatepress.php: ${file}"
		missing=1
	fi
done
if [ "$missing" -ne 0 ]; then
	exit 1
fi
echo "Every src/ file is loaded by the main plugin file."
