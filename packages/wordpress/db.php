<?php

/**
 * Hibari WordPress database drop-in.
 *
 * Copy this file to wp-content/db.php and make the Hibari WordPress package
 * available through HIBARI_WORDPRESS_ROOT. Runtime transport configuration is
 * intentionally supplied by a bridge factory; this drop-in never talks to a
 * concrete backend such as kintone directly.
 */

$hibari_root = defined('HIBARI_WORDPRESS_ROOT')
    ? HIBARI_WORDPRESS_ROOT
    : __DIR__ . '/hibari-wordpress';

require_once $hibari_root . '/src/Bridge.php';
require_once $hibari_root . '/src/SqlPreflight.php';
require_once $hibari_root . '/src/HibariWpdb.php';
require_once $hibari_root . '/src/TaxonomyProjection.php';

if (defined('HIBARI_WORDPRESS_BRIDGE_BOOTSTRAP')) {
    require_once HIBARI_WORDPRESS_BRIDGE_BOOTSTRAP;
}

$hibari_factory = isset($GLOBALS['hibari_wordpress_bridge_factory'])
    ? $GLOBALS['hibari_wordpress_bridge_factory']
    : null;

if (!is_callable($hibari_factory)) {
    throw new RuntimeException(
        'Hibari WordPress db.php requires $GLOBALS["hibari_wordpress_bridge_factory"] to be callable.'
    );
}

$hibari_bridge = call_user_func($hibari_factory);

if (!$hibari_bridge instanceof \Hibari\WordPress\Bridge) {
    throw new RuntimeException('Hibari WordPress bridge factory must return a Bridge implementation.');
}

$GLOBALS['wpdb'] = new \Hibari\WordPress\HibariWpdb($hibari_bridge);
\Hibari\WordPress\TaxonomyProjection::register($hibari_bridge);
