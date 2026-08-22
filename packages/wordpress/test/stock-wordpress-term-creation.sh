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
node "$repo_root/test/fixtures/wordpress-kintone-term-runtime.mjs" "$endpoint_file" "$request_log" \
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
  echo "Hibari term runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-term-creation.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if ! grep -Fq '"app":89' "$request_log"; then
  echo "Term creation proof never reached the configured Term app" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":87' "$request_log"; then
  echo "Term creation proof never reached the configured TermTaxonomy app" >&2
  cat "$request_log" >&2
  exit 1
fi

term_writes="$(grep -F '"app":89' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$term_writes" != "1" ]]; then
  echo "Duplicate create should leave exactly one Term record, observed writes: $term_writes" >&2
  cat "$request_log" >&2
  exit 1
fi

context_writes="$(grep -F '"app":87' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$context_writes" != "1" ]]; then
  echo "Duplicate create should leave exactly one TermTaxonomy record, observed writes: $context_writes" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Term_name":{"value":"Hibari Category"}' "$request_log"; then
  echo "Created term name did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Slug":{"value":"hibari-category"}' "$request_log"; then
  echo "Generated term slug did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Taxonomy":{"value":"category"}' "$request_log" || \
   ! grep -Fq '"Parent":{"value":0}' "$request_log" || \
   ! grep -Fq '"Count":{"value":0}' "$request_log"; then
  echo "Created TermTaxonomy context was not persisted correctly" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Slug = \"hibari-category\"' "$request_log"; then
  echo "Slug uniqueness lookup was not pushed down as bounded Term query" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Taxonomy = \"category\"' "$request_log"; then
  echo "Taxonomy context lookup was not pushed down before backend execution" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- Kintone term creation request evidence ---"
cat "$request_log"
