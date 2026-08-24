#!/usr/bin/env php
<?php

$package_root = realpath(dirname(__DIR__));
if (false === $package_root) {
    fwrite(STDERR, "Unable to resolve Hibari WordPress package root.\n");
    exit(2);
}

require_once $package_root . '/src/Bridge.php';
require_once $package_root . '/src/SqlPreflight.php';
require_once $package_root . '/src/CompatibilityReport.php';
require_once $package_root . '/src/PluginCompatibilityScanner.php';
require_once $package_root . '/src/CompatibilityPolicy.php';

$strict = false;
$directory = null;
foreach (array_slice($argv, 1) as $argument) {
    if ('--strict' === $argument) {
        $strict = true;
        continue;
    }
    if (strlen($argument) > 0 && '-' === $argument[0]) {
        fwrite(STDERR, "Usage: check-plugin.php [--strict] <plugin-directory>\n");
        exit(2);
    }
    if (null !== $directory) {
        fwrite(STDERR, "Usage: check-plugin.php [--strict] <plugin-directory>\n");
        exit(2);
    }
    $directory = $argument;
}

if (null === $directory) {
    fwrite(STDERR, "Usage: check-plugin.php [--strict] <plugin-directory>\n");
    exit(2);
}

try {
    $report = \Hibari\WordPress\PluginCompatibilityScanner::inspectDirectory($directory);
    $policy = \Hibari\WordPress\CompatibilityPolicy::evaluate($report, $strict);
    $output = array(
        'report' => $report,
        'policy' => $policy,
    );
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($policy['exitCode']);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
