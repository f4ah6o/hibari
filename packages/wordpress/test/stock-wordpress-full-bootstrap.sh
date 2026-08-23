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

theme_dir="$(find "$tmp/wordpress/wp-content/themes" -mindepth 1 -maxdepth 1 -type d -exec test -f '{}/style.css' ';' -print | LC_ALL=C sort | head -n 1)"
if [[ -z "$theme_dir" ]]; then
  echo "Stock WordPress tarball contains no bundled theme with style.css" >&2
  exit 1
fi
theme_slug="$(basename "$theme_dir")"

endpoint_file="$tmp/runtime-url"
request_log="$tmp/kintone-requests.jsonl"
touch "$request_log"
node "$repo_root/test/fixtures/wordpress-kintone-bootstrap-runtime.mjs" \
  "$endpoint_file" "$request_log" "$theme_slug" >"$tmp/runtime.log" 2>&1 &
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
  echo "Hibari full-bootstrap runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-full-bootstrap.php" \
  "$tmp/wordpress" "$runtime_url" "$theme_slug"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if ! grep -Fq '"app":84' "$request_log"; then
  echo "Normal bootstrap never reached the configured Kintone Option app" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Autoload in (' "$request_log"; then
  echo "Normal bootstrap did not execute WordPress autoload Option preload through Hibari" >&2
  cat "$request_log" >&2
  exit 1
fi

if grep -Eq '"app":(?!84)' "$request_log" 2>/dev/null; then
  echo "Normal bootstrap unexpectedly used a non-Option Kintone app" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- bundled theme ---"
echo "$theme_slug"
echo "--- Kintone full-bootstrap request evidence ---"
cat "$request_log"
