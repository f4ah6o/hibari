<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress draft/publish proof inputs.\n");
    exit(1);
}

function hibari_publish_assert($condition, $message) {
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

hibari_publish_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_publish_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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

// Keep this proof on persisted Post state. Revision and changed-slug/date
// maintenance are separate lifecycle domains and are already isolated by the
// earlier Post proof.
remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
remove_action('post_updated', 'wp_save_post_revision', 10);
remove_action('post_updated', 'wp_check_for_changed_slugs', 12);
remove_action('post_updated', 'wp_check_for_changed_dates', 12);

// Stock wp_publish_post() persists post_status before firing lifecycle hooks.
// The default transition callbacks own GUID/cache/term-count and fresh-site
// side effects that require modules/domains intentionally omitted by SHORTINIT.
// Remove only those registered Core side effects; do not replace or fake them.
remove_action('transition_post_status', '_transition_post_status', 5);
remove_action('transition_post_status', '_update_term_count_on_transition_post_status', 10);
remove_action('publish_page', '_delete_option_fresh_site', 0);

$post_id = wp_insert_post(
    array(
        'post_author' => 0,
        'post_date' => '2026-08-23 00:00:00',
        'post_date_gmt' => '2026-08-23 00:00:00',
        'post_content' => 'Hibari publish-state body',
        'post_content_filtered' => '',
        'post_title' => 'Hibari publish-state page',
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
        'guid' => 'urn:hibari:page:publish-state',
    ),
    true,
    false
);

hibari_publish_assert(!is_wp_error($post_id), 'stock wp_insert_post() returned WP_Error');
hibari_publish_assert(is_int($post_id) && $post_id > 0, 'draft page did not receive an integer identity');

$draft = get_post($post_id);
hibari_publish_assert($draft instanceof WP_Post, 'draft page could not be read');
hibari_publish_assert('draft' === $draft->post_status, 'initial page was not draft');
hibari_publish_assert('Hibari publish-state page' === $draft->post_title, 'draft title changed');
hibari_publish_assert('Hibari publish-state body' === $draft->post_content, 'draft content changed');
hibari_publish_assert('page' === $draft->post_type, 'draft record was not a page');
hibari_publish_assert(0 === (int) $draft->post_parent, 'draft parent changed');

wp_publish_post($post_id);

clean_post_cache($post_id);
$published = get_post($post_id);
hibari_publish_assert($published instanceof WP_Post, 'published page could not be read back');
hibari_publish_assert($post_id === (int) $published->ID, 'publish changed Post identity');
hibari_publish_assert('publish' === $published->post_status, 'publish status was not persisted');
hibari_publish_assert('Hibari publish-state page' === $published->post_title, 'publish changed title');
hibari_publish_assert('Hibari publish-state body' === $published->post_content, 'publish changed content');
hibari_publish_assert('page' === $published->post_type, 'publish changed post type');
hibari_publish_assert(0 === (int) $published->post_parent, 'publish changed parent');

echo "WordPress draft -> publish state -> Hibari -> KintoneBackend proof: ok\n";
