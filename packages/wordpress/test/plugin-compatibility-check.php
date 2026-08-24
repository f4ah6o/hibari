<?php

$package_root = realpath(dirname(__DIR__));
if (false === $package_root) {
    fwrite(STDERR, "Unable to resolve Hibari WordPress package root.\n");
    exit(1);
}

require_once $package_root . '/src/Bridge.php';
require_once $package_root . '/src/SqlPreflight.php';
require_once $package_root . '/src/CompatibilityReport.php';
require_once $package_root . '/src/PluginCompatibilityScanner.php';

function hibari_plugin_scan_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture_root = __DIR__ . '/fixtures/plugin-source';
$fixture_source = file_get_contents($fixture_root . '/hibari-fixture-plugin.php');
hibari_plugin_scan_assert(
    false !== $fixture_source && false !== strpos($fixture_source, 'SCANNED_PLUGIN_EXECUTED'),
    'Fixture must retain the execution sentinel.'
);

$report = \Hibari\WordPress\PluginCompatibilityScanner::inspectDirectory($fixture_root);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$golden = file_get_contents(__DIR__ . '/fixtures/plugin-source-compatibility.golden.json');

hibari_plugin_scan_assert(false !== $golden, 'Unable to read plugin compatibility golden fixture.');
hibari_plugin_scan_assert($golden === $json, 'Plugin compatibility report golden output changed.');
hibari_plugin_scan_assert(false === $report['complete'], 'Dynamic SQL must make the static scan incomplete.');
hibari_plugin_scan_assert(false === $report['compatible'], 'Incomplete scan must never report compatible=true.');
hibari_plugin_scan_assert(
    array(
        'files' => 1,
        'sqlCases' => 2,
        'portable' => 1,
        'unsupported' => 1,
        'uninspectable' => 1,
    ) === $report['summary'],
    'Plugin compatibility scan summary changed.'
);

hibari_plugin_scan_assert(
    'HIB-WP-JOIN-001' === $report['items'][1]['diagnostics'][0]['code'],
    'Unsupported source SQL lost the stable JOIN diagnostic.'
);
hibari_plugin_scan_assert(
    'HIB-WP-SCAN-001' === $report['diagnostics'][0]['code'],
    'Dynamic SQL did not produce the stable scan diagnostic.'
);
hibari_plugin_scan_assert(
    array('file' => 'hibari-fixture-plugin.php', 'line' => 10) === $report['items'][1]['source'],
    'Unsupported source location changed.'
);
hibari_plugin_scan_assert(
    array('file' => 'hibari-fixture-plugin.php', 'line' => 15) === $report['diagnostics'][0]['source'],
    'Dynamic SQL source location changed.'
);

echo $json;
echo "WordPress plugin static compatibility check proof: ok\n";
