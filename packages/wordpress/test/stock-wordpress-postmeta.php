<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress postmeta proof inputs.\n");
    exit(1);
}

function hibari_postmeta_assert($condition, $message) {
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

hibari_postmeta_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_postmeta_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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
    'wp-includes/revision.php',
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
remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
remove_action('post_updated', 'wp_save_post_revision', 10);
remove_action('post_updated', 'wp_check_for_changed_slugs', 12);
remove_action('post_updated', 'wp_check_for_changed_dates', 12);

$post_id = wp_insert_post(
    array(
        'post_author' => 0,
        'post_date' => '2026-08-22 00:00:00',
        'post_date_gmt' => '2026-08-22 00:00:00',
        'post_content' => 'Postmeta owner body',
        'post_content_filtered' => '',
        'post_title' => 'Postmeta owner page',
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
        'guid' => 'urn:hibari:page:postmeta-owner',
    ),
    true,
    false
);

hibari_postmeta_assert(!is_wp_error($post_id), 'postmeta owner page creation returned WP_Error');
hibari_postmeta_assert(is_int($post_id) && $post_id > 0, 'postmeta owner page did not receive an ID');

$first_id = add_post_meta($post_id, 'hibari_label', 'one');
$second_id = add_post_meta($post_id, 'hibari_label', 'two');
hibari_postmeta_assert(is_int($first_id) && $first_id > 0, 'first multi-value metadata add failed');
hibari_postmeta_assert(is_int($second_id) && $second_id > 0 && $second_id !== $first_id, 'second multi-value metadata add failed');

$labels = get_post_meta($post_id, 'hibari_label', false);
hibari_postmeta_assert(array('one', 'two') === $labels, 'multi-value metadata read did not preserve both values');

$unique_id = add_post_meta($post_id, 'hibari_unique', 'first', true);
$duplicate_unique = add_post_meta($post_id, 'hibari_unique', 'second', true);
hibari_postmeta_assert(is_int($unique_id) && $unique_id > 0, 'unique metadata initial add failed');
hibari_postmeta_assert(false === $duplicate_unique, 'unique metadata allowed a second value for the same owner/key');
hibari_postmeta_assert('first' === get_post_meta($post_id, 'hibari_unique', true), 'unique metadata value changed unexpectedly');

$updated = update_post_meta($post_id, 'hibari_label', 'updated', 'one');
hibari_postmeta_assert(true === $updated, 'metadata update with previous value failed');
$labels = get_post_meta($post_id, 'hibari_label', false);
hibari_postmeta_assert(array('updated', 'two') === $labels, 'metadata update did not persist through backend state');

$deleted_one_value = delete_post_meta($post_id, 'hibari_label', 'two');
hibari_postmeta_assert(true === $deleted_one_value, 'metadata value-selective delete failed');
$labels = get_post_meta($post_id, 'hibari_label', false);
hibari_postmeta_assert(array('updated') === $labels, 'value-selective delete removed the wrong metadata rows');

$deleted_key = delete_post_meta($post_id, 'hibari_label');
hibari_postmeta_assert(true === $deleted_key, 'metadata key delete failed');
hibari_postmeta_assert(array() === get_post_meta($post_id, 'hibari_label', false), 'deleted metadata remained visible');

echo "WordPress postmeta dynamic attributes -> Hibari -> KintoneBackend proof: ok\n";
