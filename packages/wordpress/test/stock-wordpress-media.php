<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress media proof inputs.\n");
    exit(1);
}

function hibari_media_assert($condition, $message) {
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
define('WP_CONTENT_URL', 'https://kintone-backed.example.test/wp-content');
PHP;

$config .= "\ndefine('HIBARI_WORDPRESS_ROOT', " . var_export($package_root, true) . ");\n";
$config .= "define('HIBARI_WORDPRESS_BRIDGE_BOOTSTRAP', " . var_export($bridge_bootstrap, true) . ");\n";
$config .= "define('HIBARI_RUNTIME_HTTP_URL', " . var_export($runtime_url, true) . ");\n";
$config .= "\$table_prefix = 'wp_';\n";
$config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
$config .= "require_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($wordpress_root . '/wp-config.php', $config);

require $wordpress_root . '/wp-load.php';

hibari_media_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_media_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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
remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
remove_action('post_updated', 'wp_save_post_revision', 10);
remove_action('post_updated', 'wp_check_for_changed_slugs', 12);
remove_action('post_updated', 'wp_check_for_changed_dates', 12);

// Slug collision discovery is a separate WP_Query compatibility domain. Keep
// this focused datastore proof on attachment-as-Post semantics by using the
// stock Core short-circuit hooks rather than teaching Hibari generic WP_Query.
add_filter('add_trashed_suffix_to_trashed_posts', '__return_false');
// default-filters.php wires this template-specific callback before the
// corresponding full-bootstrap module is loaded. SHORTINIT intentionally omits
// that module, and template slug semantics are outside this proof.
remove_filter('pre_wp_unique_post_slug', 'wp_filter_wp_template_unique_post_slug', 10);
add_filter(
    'pre_wp_unique_post_slug',
    static function ($override, $slug) {
        return $slug;
    },
    10,
    2
);

$attachment_id = wp_insert_attachment(
    array(
        'post_author' => 0,
        'post_date' => '2026-08-23 00:00:00',
        'post_date_gmt' => '2026-08-23 00:00:00',
        'post_content' => '',
        'post_content_filtered' => '',
        'post_title' => 'Hibari proof image',
        'post_excerpt' => 'Media metadata proof',
        'post_status' => 'inherit',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'post_name' => '',
        'to_ping' => '',
        'pinged' => '',
        'menu_order' => 0,
        'post_mime_type' => 'image/jpeg',
        'guid' => 'https://example.test/uploads/2026/08/hibari-proof.jpg',
    ),
    false,
    42,
    true,
    false
);

hibari_media_assert(!is_wp_error($attachment_id), 'stock wp_insert_attachment() returned WP_Error');
hibari_media_assert(is_int($attachment_id) && $attachment_id > 0, 'attachment did not receive an integer identity');

$attachment = get_post($attachment_id);
hibari_media_assert($attachment instanceof WP_Post, 'get_post() did not return the attachment');
hibari_media_assert('attachment' === $attachment->post_type, 'attachment was not stored as post_type=attachment');
hibari_media_assert('image/jpeg' === $attachment->post_mime_type, 'attachment MIME type changed');
hibari_media_assert('Hibari proof image' === $attachment->post_title, 'attachment title changed');
hibari_media_assert(42 === (int) $attachment->post_parent, 'attachment parent changed');

$logical_file = '2026/08/hibari-proof.jpg';
$file_result = update_attached_file($attachment_id, $logical_file);
hibari_media_assert(false !== $file_result, 'update_attached_file() failed');
wp_cache_delete($attachment_id, 'post_meta');
hibari_media_assert(
    $logical_file === get_post_meta($attachment_id, '_wp_attached_file', true),
    '_wp_attached_file did not round-trip through PostMeta'
);
$resolved_file = get_attached_file($attachment_id, true);
hibari_media_assert(
    is_string($resolved_file) && str_ends_with(str_replace('\\', '/', $resolved_file), $logical_file),
    'get_attached_file() did not preserve the logical attached-file path'
);

$metadata = array(
    'width' => 640,
    'height' => 480,
    'file' => $logical_file,
    'sizes' => array(
        'thumbnail' => array(
            'file' => 'hibari-proof-150x150.jpg',
            'width' => 150,
            'height' => 150,
            'mime-type' => 'image/jpeg',
        ),
    ),
    'image_meta' => array(
        'camera' => 'Hibari Fixture',
        'orientation' => 1,
    ),
);

$metadata_result = wp_update_attachment_metadata($attachment_id, $metadata);
hibari_media_assert(false !== $metadata_result, 'wp_update_attachment_metadata() failed');
wp_cache_delete($attachment_id, 'post_meta');
$stored_metadata = wp_get_attachment_metadata($attachment_id);
hibari_media_assert(is_array($stored_metadata), 'wp_get_attachment_metadata() did not return an array');
hibari_media_assert(640 === $stored_metadata['width'], 'attachment metadata width changed');
hibari_media_assert(480 === $stored_metadata['height'], 'attachment metadata height changed');
hibari_media_assert($logical_file === $stored_metadata['file'], 'attachment metadata file changed');
hibari_media_assert(
    'hibari-proof-150x150.jpg' === $stored_metadata['sizes']['thumbnail']['file'],
    'nested attachment metadata did not round-trip'
);
hibari_media_assert(
    'Hibari Fixture' === $stored_metadata['image_meta']['camera'],
    'nested image metadata did not round-trip'
);

$metadata['width'] = 800;
$metadata['height'] = 600;
$metadata['sizes']['thumbnail']['width'] = 160;
$updated_metadata_result = wp_update_attachment_metadata($attachment_id, $metadata);
hibari_media_assert(false !== $updated_metadata_result, 'attachment metadata update failed');
wp_cache_delete($attachment_id, 'post_meta');
$updated_metadata = wp_get_attachment_metadata($attachment_id);
hibari_media_assert(800 === $updated_metadata['width'], 'updated attachment width did not persist');
hibari_media_assert(600 === $updated_metadata['height'], 'updated attachment height did not persist');
hibari_media_assert(160 === $updated_metadata['sizes']['thumbnail']['width'], 'updated nested metadata did not persist');

echo "WordPress media metadata -> Post + Dynamic Attributes -> Hibari -> KintoneBackend proof: ok\n";
