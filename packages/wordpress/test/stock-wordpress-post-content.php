<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress post-content proof inputs.\n");
    exit(1);
}

function hibari_post_assert($condition, $message) {
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

hibari_post_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_post_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

// SHORTINIT intentionally keeps the proof away from the full site/plugin/theme
// boot. Load only stock Core modules required by the public post APIs. Seed an
// anonymous WP_User before pluggable.php so current-user resolution does not
// consult authentication cookies or the user datastore, which are separate
// WordPress consumer domains.
$core_before_user = array(
    'wp-includes/capabilities.php',
    'wp-includes/class-wp-roles.php',
    'wp-includes/class-wp-role.php',
    'wp-includes/class-wp-user.php',
    'wp-includes/user.php',
);
foreach ($core_before_user as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}
$GLOBALS['current_user'] = new WP_User(0);

$core_files = array(
    'wp-includes/pluggable.php',
    'wp-includes/theme.php',
    'wp-includes/author-template.php',
    'wp-includes/general-template.php',
    'wp-includes/class-wp-block-parser-block.php',
    'wp-includes/class-wp-block-parser-frame.php',
    'wp-includes/class-wp-block-parser.php',
    'wp-includes/blocks.php',
    'wp-includes/kses.php',
    'wp-includes/cron.php',
    'wp-includes/post.php',
    'wp-includes/class-wp-post-type.php',
    'wp-includes/class-wp-post.php',
    'wp-includes/nav-menu.php',
    'wp-includes/taxonomy.php',
    'wp-includes/class-wp-taxonomy.php',
    'wp-includes/class-wp-term.php',
    'wp-includes/class-wp-term-query.php',
    'wp-includes/class-wp-tax-query.php',
);
foreach ($core_files as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}

wp_installing(true);

$post_id = wp_insert_post(
    array(
        'post_author' => 0,
        'post_date' => '2026-08-22 00:00:00',
        'post_date_gmt' => '2026-08-22 00:00:00',
        'post_content' => 'Hibari initial body',
        'post_content_filtered' => '',
        'post_title' => 'Hibari draft page',
        'post_excerpt' => '',
        'post_status' => 'draft',
        'post_type' => 'page',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'post_name' => '',
        'to_ping' => '',
        'pinged' => '',
        'post_parent' => 0,
        'menu_order' => 0,
        'post_mime_type' => '',
        'guid' => 'urn:hibari:page:draft',
    ),
    true,
    false
);

hibari_post_assert(!is_wp_error($post_id), 'stock wp_insert_post() returned WP_Error');
hibari_post_assert(is_int($post_id) && $post_id > 0, 'stock wp_insert_post() did not return a generated post ID');

$created = get_post($post_id);
hibari_post_assert($created instanceof WP_Post, 'stock get_post() did not return WP_Post');
hibari_post_assert('Hibari draft page' === $created->post_title, 'created page title was not read from KintoneBackend');
hibari_post_assert('Hibari initial body' === $created->post_content, 'created page content was not read from KintoneBackend');
hibari_post_assert('draft' === $created->post_status, 'created page status changed unexpectedly');
hibari_post_assert('page' === $created->post_type, 'created record was not a page');

// wp_update_post() expands WP_Post to ARRAY_A before merging changes. Core's
// WP_Post::to_array() always asks for the virtual page_template property, which
// falls through to postmeta on a cache miss. This child intentionally excludes
// wp_postmeta, and this post was just created without metadata, so prime the
// authoritative empty metadata set instead of teaching the production SQL
// translator a fake postmeta implementation.
wp_cache_set($post_id, array(), 'post_meta');

$updated_id = wp_update_post(
    array(
        'ID' => $post_id,
        'post_title' => 'Hibari updated page',
        'post_content' => 'Hibari updated body',
    ),
    true,
    false
);
hibari_post_assert(!is_wp_error($updated_id), 'stock wp_update_post() returned WP_Error');
hibari_post_assert($post_id === $updated_id, 'stock wp_update_post() changed the post identity');

clean_post_cache($post_id);
$updated = get_post($post_id);
hibari_post_assert($updated instanceof WP_Post, 'updated post could not be read back');
hibari_post_assert('Hibari updated page' === $updated->post_title, 'updated page title was not persisted');
hibari_post_assert('Hibari updated body' === $updated->post_content, 'updated page content was not persisted');
hibari_post_assert('draft' === $updated->post_status, 'update changed draft status unexpectedly');

echo "WordPress post content CRU -> Hibari -> KintoneBackend proof: ok\n";
