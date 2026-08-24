<?php

$package_root = realpath(dirname(__DIR__));
if (false === $package_root) {
    fwrite(STDERR, "Unable to resolve Hibari WordPress package root.\n");
    exit(1);
}

require_once $package_root . '/src/CompatibilityPolicy.php';

function hibari_policy_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$warning_only = array(
    'complete' => true,
    'summary' => array(
        'native' => 1,
        'emulated' => 1,
        'expensive' => 1,
        'unsupported' => 0,
        'uninspectable' => 0,
    ),
);

$default = \Hibari\WordPress\CompatibilityPolicy::evaluate($warning_only, false);
$strict = \Hibari\WordPress\CompatibilityPolicy::evaluate($warning_only, true);

hibari_policy_assert(true === $default['passed'], 'Default mode must allow compatible emulated/expensive operations.');
hibari_policy_assert(0 === $default['exitCode'], 'Default warning-only report must exit 0.');
hibari_policy_assert(array() === $default['reasons'], 'Default warning-only report must not produce failure reasons.');
hibari_policy_assert(false === $strict['passed'], 'Strict mode must reject emulated/expensive operations.');
hibari_policy_assert(1 === $strict['exitCode'], 'Strict warning-only report must exit 1.');
hibari_policy_assert(array('emulated', 'expensive') === $strict['reasons'], 'Strict reason ordering changed.');

$unsupported = array(
    'complete' => true,
    'summary' => array(
        'native' => 0,
        'emulated' => 0,
        'expensive' => 0,
        'unsupported' => 1,
        'uninspectable' => 0,
    ),
);
$unsupported_result = \Hibari\WordPress\CompatibilityPolicy::evaluate($unsupported, false);
hibari_policy_assert(false === $unsupported_result['passed'], 'Unsupported operations must fail default mode.');
hibari_policy_assert(array('unsupported') === $unsupported_result['reasons'], 'Unsupported failure reason changed.');

$incomplete = array(
    'complete' => false,
    'summary' => array(
        'native' => 1,
        'emulated' => 0,
        'expensive' => 0,
        'unsupported' => 0,
        'uninspectable' => 1,
    ),
);
$incomplete_result = \Hibari\WordPress\CompatibilityPolicy::evaluate($incomplete, false);
hibari_policy_assert(false === $incomplete_result['passed'], 'Incomplete scans must fail default mode.');
hibari_policy_assert(array('incomplete') === $incomplete_result['reasons'], 'Incomplete failure reason changed.');

echo "WordPress default/strict compatibility policy proof: ok\n";
