<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress taxonomy proof inputs.\n");
    exit(1);
}

function hibari_taxonomy_assert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

copy($package_root . '/db.php', $wordpress_root . '/wp-content/db.php');

$config = <<<'PHP'
<?php

define('DB_NAME', 'hibari');
define('DB_USER', 'hibari');
define('DB_PASSWORD', 'hibari');
define('DB_HOST', 'invalid.example');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('SHORTINIT', true);
define('WP_DEBUG', false);
PHP;

$config .= "\ndefine('HIBARI_WORDPRESS_ROOT', " . var_export($package_root, true) . ");\n";
$config .= "define('HIBARI_WORDPRESS_BRIDGE_BOOTSTRAP', " . var_export($bridge_bootstrap, true) . ");\n";
$config .= "define('HIBARI_RUNTIME_HTTP_URL', " . var_export($runtime_url, true) . ");\n";
$config .= "\$table_prefix = 'wp_';\n";
$config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
$config .= "require_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($wordpress_root . '/wp-config.php', $config);

require $wordpress_root . '/wp-load.php';

hibari_taxonomy_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_taxonomy_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

$core_files = array(
    'wp-includes/l10n.php',
    'wp-includes/post.php',
    'wp-includes/post-formats.php',
    'wp-includes/class-wp-taxonomy.php',
    'wp-includes/class-wp-term.php',
    'wp-includes/class-wp-term-query.php',
    'wp-includes/taxonomy.php',
);
foreach ($core_files as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}

register_taxonomy(
    'category',
    'post',
    array(
        'hierarchical' => true,
        'public' => false,
        'show_ui' => false,
        'rewrite' => false,
        'query_var' => false,
    )
);
hibari_taxonomy_assert(taxonomy_exists('category'), 'category taxonomy was not registered');

// Term count maintenance is a separate taxonomy concern. Keep this relation
// proof on the public APIs while deferring count recomputation so no hidden
// aggregate/JOIN compatibility is introduced merely to exercise edge semantics.
wp_defer_term_counting(true);

$object_id = 42;
$term_id = 7;
$term_taxonomy_id = 1;

$term_info = term_exists($term_id, 'category');
hibari_taxonomy_assert(is_array($term_info), 'stock term_exists() did not resolve the fixture term context');
hibari_taxonomy_assert($term_id === (int) $term_info['term_id'], 'term_exists() changed the term identity');
hibari_taxonomy_assert(
    $term_taxonomy_id === (int) $term_info['term_taxonomy_id'],
    'term_exists() did not resolve the expected taxonomy context'
);

$before = wp_get_object_terms(
    $object_id,
    'category',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_taxonomy_assert(array() === $before, 'relation fixture was not initially empty');

$set = wp_set_object_terms($object_id, array($term_id), 'category', false);
hibari_taxonomy_assert(!is_wp_error($set), 'stock wp_set_object_terms() returned WP_Error');
hibari_taxonomy_assert(
    array($term_taxonomy_id) === array_map('intval', $set),
    'wp_set_object_terms() did not return the expected term taxonomy ID'
);

$attached = wp_get_object_terms(
    $object_id,
    'category',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_taxonomy_assert(
    array($term_taxonomy_id) === array_map('intval', $attached),
    'later public taxonomy read did not observe the attached relation'
);

$repeat = wp_set_object_terms($object_id, array($term_id), 'category', false);
hibari_taxonomy_assert(!is_wp_error($repeat), 'duplicate stock wp_set_object_terms() returned WP_Error');
hibari_taxonomy_assert(
    array($term_taxonomy_id) === array_map('intval', $repeat),
    'duplicate attach changed the relation identity'
);

$after_repeat = wp_get_object_terms(
    $object_id,
    'category',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_taxonomy_assert(
    array($term_taxonomy_id) === array_map('intval', $after_repeat),
    'duplicate attach created a visible duplicate relation'
);

$removed = wp_remove_object_terms($object_id, array($term_id), 'category');
hibari_taxonomy_assert(true === $removed, 'stock wp_remove_object_terms() did not report success');

$after_remove = wp_get_object_terms(
    $object_id,
    'category',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_taxonomy_assert(array() === $after_remove, 'removed taxonomy relation remained visible');

echo "WordPress taxonomy relation edges -> Hibari -> KintoneBackend proof: ok\n";
