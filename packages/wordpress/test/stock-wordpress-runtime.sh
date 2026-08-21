#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
package_root="$repo_root/packages/wordpress"
wordpress_version="7.1"
tmp="$(mktemp -d)"
runtime_pid=""

cleanup() {
  if [[ -n "$runtime_pid" ]] && kill -0 "$runtime_pid" 2>/dev/null; then
    kill "$runtime_pid" 2>/dev/null || true
    wait "$runtime_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

for php_file in "$package_root"/db.php "$package_root"/src/*.php "$package_root"/test/*.php; do
  php -l "$php_file" >/dev/null
done

npm run build --silent

curl -fsSL "https://wordpress.org/wordpress-${wordpress_version}.tar.gz" -o "$tmp/wordpress.tar.gz"
tar -xzf "$tmp/wordpress.tar.gz" -C "$tmp"

actual_version="$(php -r 'include $argv[1]; echo $wp_version;' "$tmp/wordpress/wp-includes/version.php")"
if [[ "$actual_version" != "$wordpress_version" ]]; then
  echo "Expected WordPress $wordpress_version, got $actual_version" >&2
  exit 1
fi

endpoint_file="$tmp/runtime-url"
request_log="$tmp/kintone-requests.jsonl"
touch "$request_log"
node "$repo_root/test/fixtures/wordpress-kintone-runtime.mjs" "$endpoint_file" "$request_log" \
  >"$tmp/runtime.log" 2>&1 &
runtime_pid=$!

for _ in $(seq 1 100); do
  if [[ -s "$endpoint_file" ]]; then
    break
  fi
  if ! kill -0 "$runtime_pid" 2>/dev/null; then
    cat "$tmp/runtime.log" >&2
    exit 1
  fi
  sleep 0.05
done

if [[ ! -s "$endpoint_file" ]]; then
  echo "Hibari runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
php "$package_root/test/stock-wordpress-runtime.php" "$tmp/wordpress" "$runtime_url"

if ! grep -Fq 'Option_name = \"siteurl\"' "$request_log"; then
  echo "Fake Kintone transport did not observe the translated option selector" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Option_value' "$request_log"; then
  echo "Fake Kintone transport did not observe the translated option projection" >&2
  cat "$request_log" >&2
  exit 1
fi

request_count="$(wc -l < "$request_log" | tr -d ' ')"
if [[ "$request_count" != "1" ]]; then
  echo "Expected exactly one Kintone REST request; got $request_count" >&2
  cat "$request_log" >&2
  exit 1
fi
