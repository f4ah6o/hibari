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
if ! php "$package_root/test/stock-wordpress-media.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if ! grep -Fq '"app":85' "$request_log"; then
  echo "Media proof never reached the configured Post app" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":86' "$request_log"; then
  echo "Media proof never reached the configured PostMeta app" >&2
  cat "$request_log" >&2
  exit 1
fi

post_creates="$(grep -F '"app":85' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$post_creates" != "1" ]]; then
  echo "Expected exactly one attachment Post create, observed: $post_creates" >&2
  cat "$request_log" >&2
  exit 1
fi

for evidence in \
  '"Post_type":{"value":"attachment"}' \
  '"Post_mime_type":{"value":"image/jpeg"}' \
  '"Post_parent":{"value":42}' \
  '"Meta_key":{"value":"_wp_attached_file"}' \
  '2026/08/hibari-proof.jpg' \
  '"Meta_key":{"value":"_wp_attachment_metadata"}'; do
  if ! grep -Fq "$evidence" "$request_log"; then
    echo "Expected media datastore evidence not observed: $evidence" >&2
    cat "$request_log" >&2
    exit 1
  fi
done

if ! grep -Fq 'i:640' "$request_log" || ! grep -Fq 'i:480' "$request_log"; then
  echo "Initial structured attachment metadata was not serialized by WordPress across the PostMeta boundary" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'i:800' "$request_log" || ! grep -Fq 'i:600' "$request_log" || ! grep -Fq 'i:160' "$request_log"; then
  echo "Updated structured attachment metadata was not persisted opaquely through PostMeta" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -F '"app":86' "$request_log" | grep -Fq '"method":"PUT","path":"/k/v1/records.json"'; then
  echo "Attachment metadata update did not reach PostMeta mutation path" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- Kintone media request evidence ---"
grep -E '"app":(85|86)' "$request_log" | sed -n '1,60p'
