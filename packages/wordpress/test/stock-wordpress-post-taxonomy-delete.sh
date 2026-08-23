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
node "$repo_root/test/fixtures/wordpress-kintone-post-taxonomy-delete-runtime.mjs" \
  "$endpoint_file" "$request_log" >"$tmp/runtime.log" 2>&1 &
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
  echo "Hibari post taxonomy-delete runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-post-taxonomy-delete.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

node --input-type=module - "$request_log" <<'NODE'
import { readFileSync } from "node:fs";

const requestLog = process.argv[2];
const requests = readFileSync(requestLog, "utf8")
  .split("\n")
  .filter(Boolean)
  .map((line) => JSON.parse(line));

function fail(message) {
  console.error(message);
  console.error(readFileSync(requestLog, "utf8"));
  process.exit(1);
}

function requestsFor(app, method, suffix) {
  return requests.filter(
    (request) =>
      Number(request.body?.app) === app
      && request.method === method
      && request.path.endsWith(suffix)
  );
}

if (requestsFor(85, "POST", "/record.json").length !== 1) {
  fail("Expected exactly one Post create through app 85");
}
if (requestsFor(89, "POST", "/record.json").length !== 2) {
  fail("Expected exactly two Term creates through app 89");
}
if (requestsFor(87, "POST", "/record.json").length !== 2) {
  fail("Expected exactly two TermTaxonomy creates through app 87");
}
if (requestsFor(88, "POST", "/record.json").length !== 2) {
  fail("Expected exactly two Relation Edge creates through app 88");
}

const relationDeleteIds = requestsFor(88, "DELETE", "/records.json")
  .flatMap((request) => request.body?.ids ?? [])
  .map(String);
if (!relationDeleteIds.includes("1") || !relationDeleteIds.includes("2")) {
  fail(`Expected actual Relation Edge deletes for identities 1 and 2, got ${relationDeleteIds.join(",")}`);
}

if (requestsFor(87, "DELETE", "/records.json").length !== 0) {
  fail("Post deletion must not delete TermTaxonomy records");
}
if (requestsFor(89, "DELETE", "/records.json").length !== 0) {
  fail("Post deletion must not delete Term records");
}

const postDeleteIds = requestsFor(85, "DELETE", "/records.json")
  .flatMap((request) => request.body?.ids ?? [])
  .map(String);
if (!postDeleteIds.includes("1")) {
  fail("wp_delete_post() did not delete Post identity 1 through app 85");
}

const taxonomyQueries = requests
  .filter((request) => Number(request.body?.app) === 87)
  .map((request) => String(request.body?.query ?? ""));
if (!taxonomyQueries.some((query) => query.includes('Taxonomy = "category"'))) {
  fail("No bounded category TermTaxonomy query was observed");
}
if (!taxonomyQueries.some((query) => query.includes('Taxonomy = "post_tag"'))) {
  fail("No bounded post_tag TermTaxonomy query was observed");
}

const relationQueries = requests
  .filter((request) => Number(request.body?.app) === 88)
  .map((request) => String(request.body?.query ?? ""));
if (!relationQueries.some((query) => query.includes("Object_id in (1)") || query.includes("Object_id = 1"))) {
  fail("No object-scoped Relation Edge query for Post identity 1 was observed");
}
NODE

echo "--- Kintone post taxonomy-delete request evidence ---"
cat "$request_log"
