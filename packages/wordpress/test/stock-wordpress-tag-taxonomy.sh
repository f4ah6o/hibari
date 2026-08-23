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
  echo "Hibari tag runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-tag-taxonomy.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

for app in 89 87 88; do
  if ! grep -Fq "\"app\":$app" "$request_log"; then
    echo "Tag proof never reached expected Kintone app $app" >&2
    cat "$request_log" >&2
    exit 1
  fi
done

term_writes="$(grep -F '"app":89' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$term_writes" != "1" ]]; then
  echo "Expected exactly one Term record, observed writes: $term_writes" >&2
  cat "$request_log" >&2
  exit 1
fi

context_writes="$(grep -F '"app":87' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$context_writes" != "1" ]]; then
  echo "Expected exactly one TermTaxonomy record, observed writes: $context_writes" >&2
  cat "$request_log" >&2
  exit 1
fi

relation_writes="$(grep -F '"app":88' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$relation_writes" != "1" ]]; then
  echo "Expected exactly one tag Relation Edge, observed writes: $relation_writes" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Term_name":{"value":"Hibari Tag"}' "$request_log"; then
  echo "Created tag name did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Slug":{"value":"hibari-tag"}' "$request_log"; then
  echo "Generated tag slug did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Taxonomy":{"value":"post_tag"}' "$request_log"; then
  echo "post_tag taxonomy context was not persisted" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Taxonomy = \"post_tag\"' "$request_log"; then
  echo "post_tag taxonomy lookup was not pushed down as bounded context" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Object_id":{"value":42}' "$request_log"; then
  echo "Relation Edge did not preserve object ID 42" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Term_taxonomy_id":{"value":1}' "$request_log"; then
  echo "Relation Edge did not preserve the created TermTaxonomy identity" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -F '"app":88' "$request_log" | grep -Fq '"method":"DELETE","path":"/k/v1/records.json"'; then
  echo "Tag detach did not reach Relation Edge delete" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- Kintone post_tag request evidence ---"
cat "$request_log"
