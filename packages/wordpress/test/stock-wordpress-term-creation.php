<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress term creation proof inputs.\n");
    exit(1);
}

function hibari_term_assert($condition, $message) {
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

hibari_term_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_term_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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
hibari_term_assert(taxonomy_exists('category'), 'category taxonomy was not registered');

$created = wp_insert_term('Hibari Category', 'category');
hibari_term_assert(!is_wp_error($created), 'first stock wp_insert_term() returned WP_Error');
hibari_term_assert(is_array($created), 'first stock wp_insert_term() did not return an identity pair');
hibari_term_assert(isset($created['term_id']) && (int) $created['term_id'] > 0, 'created term has no identity');
hibari_term_assert(
    isset($created['term_taxonomy_id']) && (int) $created['term_taxonomy_id'] > 0,
    'created term taxonomy context has no identity'
);

$term_id = (int) $created['term_id'];
$term_taxonomy_id = (int) $created['term_taxonomy_id'];

$exists = term_exists($term_id, 'category');
hibari_term_assert(is_array($exists), 'term_exists() did not observe the newly created term');
hibari_term_assert($term_id === (int) $exists['term_id'], 'term_exists() changed the created term identity');
hibari_term_assert(
    $term_taxonomy_id === (int) $exists['term_taxonomy_id'],
    'term_exists() changed the created taxonomy context identity'
);

$by_slug = get_term_by('slug', 'hibari-category', 'category');
hibari_term_assert($by_slug instanceof WP_Term, 'get_term_by(slug) did not resolve the created term');
hibari_term_assert('Hibari Category' === $by_slug->name, 'created term name was not observable through stock API');
hibari_term_assert('hibari-category' === $by_slug->slug, 'generated slug was not observable through stock API');
hibari_term_assert($term_id === (int) $by_slug->term_id, 'slug lookup changed the term identity');

$duplicate = wp_insert_term('Hibari Category', 'category');
hibari_term_assert(is_wp_error($duplicate), 'duplicate stock wp_insert_term() unexpectedly succeeded');
hibari_term_assert('term_exists' === $duplicate->get_error_code(), 'duplicate insert did not return term_exists');
hibari_term_assert($term_id === (int) $duplicate->get_error_data(), 'term_exists error did not point to the original term');

$after_duplicate = term_exists($term_id, 'category');
hibari_term_assert(is_array($after_duplicate), 'original term disappeared after duplicate rejection');
hibari_term_assert(
    $term_taxonomy_id === (int) $after_duplicate['term_taxonomy_id'],
    'duplicate rejection changed the original taxonomy context'
);

echo "WordPress term creation + uniqueness -> Hibari -> KintoneBackend proof: ok\n";
