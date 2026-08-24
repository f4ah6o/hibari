<?php

$package_root = realpath(dirname(__DIR__));
if (false === $package_root) {
    fwrite(STDERR, "Unable to resolve Hibari WordPress package root.\n");
    exit(1);
}

require_once $package_root . '/src/Bridge.php';
require_once $package_root . '/src/SqlPreflight.php';
require_once $package_root . '/src/CompatibilityReport.php';

function hibari_compatibility_report_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = array(
    array(
        'id' => 'native-option-read',
        'sql' => "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1",
    ),
    array(
        'id' => 'unsupported-join',
        'sql' => 'SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID',
    ),
    array(
        'id' => 'unsupported-aggregate',
        'sql' => 'SELECT COUNT(*) FROM wp_posts',
    ),
    array(
        'id' => 'unsupported-transaction',
        'sql' => 'START TRANSACTION',
    ),
    array(
        'id' => 'unsupported-ddl',
        'sql' => 'CREATE TABLE wp_hibari_probe (id bigint)',
    ),
    array(
        'id' => 'unsupported-subquery',
        'sql' => 'SELECT ID FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta)',
    ),
);

$report = \Hibari\WordPress\CompatibilityReport::inspect($cases);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$golden_path = __DIR__ . '/fixtures/compatibility-report.golden.json';
$golden = file_get_contents($golden_path);

hibari_compatibility_report_assert(false !== $golden, 'Unable to read compatibility report golden fixture.');
hibari_compatibility_report_assert($golden === $json, 'Compatibility report golden output changed.');
hibari_compatibility_report_assert(false === $report['compatible'], 'Unsupported cases must mark the whole report incompatible.');
hibari_compatibility_report_assert(
    array('total' => 6, 'native' => 1, 'emulated' => 0, 'expensive' => 0, 'unsupported' => 5) === $report['summary'],
    'Compatibility report summary counts changed.'
);
hibari_compatibility_report_assert(
    'native' === $report['items'][0]['classification'],
    'Successful WordPress SQL must use the canonical native classification.'
);

$codes = array();
foreach ($report['items'] as $item) {
    if ('unsupported' !== $item['classification']) {
        continue;
    }
    hibari_compatibility_report_assert(
        1 === count($item['diagnostics']),
        'Each unsupported preflight case must preserve exactly one stable diagnostic.'
    );
    $codes[] = $item['diagnostics'][0]['code'];
}

hibari_compatibility_report_assert(
    array(
        'HIB-WP-JOIN-001',
        'HIB-WP-AGG-001',
        'HIB-WP-TXN-001',
        'HIB-WP-DDL-001',
        'HIB-WP-SUBQUERY-001',
    ) === $codes,
    'Stable WordPress compatibility diagnostic codes changed.'
);

echo $json;
echo "WordPress compatibility report canonical classification proof: ok\n";
