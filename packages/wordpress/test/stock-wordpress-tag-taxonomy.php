<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress tag taxonomy proof inputs.\n");
    exit(1);
}

function hibari_tag_assert($condition, $message) {
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

hibari_tag_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_tag_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

$core_files = array(
    'wp-includes/l10n.php',
    'wp-includes/class-wp-block-parser-block.php',
    'wp-includes/class-wp-block-parser-frame.php',
    'wp-includes/class-wp-block-parser.php',
    'wp-includes/blocks.php',
    'wp-includes/kses.php',
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
    'post_tag',
    'post',
    array(
        'hierarchical' => false,
        'public' => false,
        'show_ui' => false,
        'rewrite' => false,
        'query_var' => false,
    )
);
hibari_tag_assert(taxonomy_exists('post_tag'), 'post_tag taxonomy was not registered');

// Count recomputation is a separate aggregate domain. This proof is about the
// generic Term / TermTaxonomy / Relation Edge contracts, not count maintenance.
wp_defer_term_counting(true);

$created = wp_insert_term('Hibari Tag', 'post_tag');
hibari_tag_assert(!is_wp_error($created), 'stock wp_insert_term(post_tag) returned WP_Error');
hibari_tag_assert(is_array($created), 'wp_insert_term(post_tag) did not return an identity pair');
hibari_tag_assert(isset($created['term_id']) && (int) $created['term_id'] > 0, 'created tag has no Term identity');
hibari_tag_assert(
    isset($created['term_taxonomy_id']) && (int) $created['term_taxonomy_id'] > 0,
    'created tag has no TermTaxonomy identity'
);

$term_id = (int) $created['term_id'];
$term_taxonomy_id = (int) $created['term_taxonomy_id'];
$object_id = 42;

$exists = term_exists($term_id, 'post_tag');
hibari_tag_assert(is_array($exists), 'term_exists() did not observe the created post_tag');
hibari_tag_assert($term_id === (int) $exists['term_id'], 'term_exists() changed the tag Term identity');
hibari_tag_assert(
    $term_taxonomy_id === (int) $exists['term_taxonomy_id'],
    'term_exists() changed the tag taxonomy-context identity'
);

$by_slug = get_term_by('slug', 'hibari-tag', 'post_tag');
hibari_tag_assert($by_slug instanceof WP_Term, 'get_term_by(slug) did not resolve the created post_tag');
hibari_tag_assert('Hibari Tag' === $by_slug->name, 'created tag name was not observable');
hibari_tag_assert('hibari-tag' === $by_slug->slug, 'generated tag slug was not observable');
hibari_tag_assert('post_tag' === $by_slug->taxonomy, 'tag taxonomy context changed');

$before = wp_get_object_terms(
    $object_id,
    'post_tag',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_tag_assert(array() === $before, 'tag relation fixture was not initially empty');

$set = wp_set_object_terms($object_id, array($term_id), 'post_tag', false);
hibari_tag_assert(!is_wp_error($set), 'stock wp_set_object_terms(post_tag) returned WP_Error');
hibari_tag_assert(
    array($term_taxonomy_id) === array_map('intval', $set),
    'tag attach did not return the expected TermTaxonomy identity'
);

$attached = wp_get_object_terms(
    $object_id,
    'post_tag',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_tag_assert(
    array($term_taxonomy_id) === array_map('intval', $attached),
    'later public taxonomy read did not observe the attached tag'
);

$removed = wp_remove_object_terms($object_id, array($term_id), 'post_tag');
hibari_tag_assert(true === $removed, 'stock wp_remove_object_terms(post_tag) did not report success');

$after_remove = wp_get_object_terms(
    $object_id,
    'post_tag',
    array('fields' => 'tt_ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
);
hibari_tag_assert(array() === $after_remove, 'removed tag relation remained visible');

echo "WordPress post_tag Term + Relation Edge -> Hibari -> KintoneBackend proof: ok\n";
