#!/usr/bin/env bash
set -euo pipefail

package_root="$(cd "$(dirname "$0")/.." && pwd)"
cli="$package_root/bin/check-plugin.php"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

php -l "$cli" >/dev/null

mkdir -p "$tmp/native"
cat >"$tmp/native/plugin.php" <<'PHP'
<?php
$wpdb->get_var("SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1");
PHP

php "$cli" "$tmp/native" >"$tmp/native-a.json"
php "$cli" "$tmp/native" >"$tmp/native-b.json"
cmp "$tmp/native-a.json" "$tmp/native-b.json"
php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data) || true !== $data["policy"]["passed"] || 0 !== $data["policy"]["exitCode"] || "default" !== $data["policy"]["mode"]) {
    fwrite(STDERR, "Default CLI pass contract changed.\n");
    exit(1);
}
if (1 !== $data["report"]["summary"]["native"] || "native" !== $data["report"]["items"][0]["classification"]) {
    fwrite(STDERR, "CLI report did not preserve canonical native classification.\n");
    exit(1);
}
' "$tmp/native-a.json"

php "$cli" --strict "$tmp/native" >"$tmp/native-strict.json"
php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (true !== $data["policy"]["passed"] || "strict" !== $data["policy"]["mode"]) {
    fwrite(STDERR, "Strict native-only CLI contract changed.\n");
    exit(1);
}
' "$tmp/native-strict.json"

set +e
php "$cli" "$package_root/test/fixtures/plugin-source" >"$tmp/incompatible.json" 2>"$tmp/incompatible.err"
status=$?
set -e
if [[ "$status" -ne 1 ]]; then
  echo "Expected compatibility failure exit 1, got $status" >&2
  exit 1
fi

set +e
php "$cli" >"$tmp/usage.out" 2>"$tmp/usage.err"
status=$?
set -e
if [[ "$status" -ne 2 ]]; then
  echo "Expected usage error exit 2, got $status" >&2
  exit 1
fi

echo "WordPress CI-friendly compatibility CLI exit-code proof: ok"
