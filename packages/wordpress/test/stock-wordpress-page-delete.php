<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress page-delete proof inputs.\n");
    exit(1);
}

function hibari_delete_assert($condition, $message) {
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

hibari_delete_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_delete_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

$core_before_user = array(
    'wp-includes/l10n.php',
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
    'wp-includes/html-api/html5-named-character-references.php',
    'wp-includes/html-api/class-wp-html-attribute-token.php',
    'wp-includes/html-api/class-wp-html-span.php',
    'wp-includes/html-api/class-wp-html-doctype-info.php',
    'wp-includes/html-api/class-wp-html-text-replacement.php',
    'wp-includes/html-api/class-wp-html-decoder.php',
    'wp-includes/html-api/class-wp-html-tag-processor.php',
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
    'wp-includes/class-wp-comment.php',
    'wp-includes/comment.php',
);
foreach ($core_files as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}

wp_installing(true);
create_initial_post_types();

// Load revision helpers because stock Metadata APIs call wp_is_post_revision(),
// but keep revision persistence itself disabled for this brand-new draft page.
remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
remove_action('post_updated', 'wp_save_post_revision', 10);
remove_action('post_updated', 'wp_check_for_changed_slugs', 12);
remove_action('post_updated', 'wp_check_for_changed_dates', 12);

try {
    $post_id = wp_insert_post(
        array(
            'post_author' => 0,
            'post_date' => '2026-08-23 00:00:00',
            'post_date_gmt' => '2026-08-23 00:00:00',
            'post_content' => 'Hibari delete body',
            'post_content_filtered' => '',
            'post_title' => 'Hibari delete page',
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
            'guid' => 'urn:hibari:page:delete',
        ),
        true,
        false
    );
    hibari_delete_assert(!is_wp_error($post_id), 'wp_insert_post() returned WP_Error');
    hibari_delete_assert(is_int($post_id) && $post_id > 0, 'wp_insert_post() did not return a generated page ID');

    hibari_delete_assert(
        false !== add_post_meta($post_id, 'hibari_delete_meta', 'before-delete', true),
        'add_post_meta() did not persist dependent metadata'
    );

    // Keep the dependent comment unapproved so WordPress does not need comment
    // count aggregation during this focused deletion proof.
    $comment_id = wp_insert_comment(
        array(
            'comment_post_ID' => $post_id,
            'comment_author' => 'Hibari Delete Commenter',
            'comment_author_email' => 'delete-comment@example.test',
            'comment_author_url' => '',
            'comment_author_IP' => '192.0.2.77',
            'comment_date' => '2026-08-23 00:00:00',
            'comment_date_gmt' => '2026-08-23 00:00:00',
            'comment_content' => 'Dependent comment to delete',
            'comment_karma' => 0,
            'comment_approved' => 0,
            'comment_agent' => 'hibari-delete-proof',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        )
    );
    hibari_delete_assert(is_int($comment_id) && $comment_id > 0, 'wp_insert_comment() did not return a generated comment ID');

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');
    clean_comment_cache($comment_id);

    hibari_delete_assert(get_post($post_id) instanceof WP_Post, 'created page was not readable before delete');
    hibari_delete_assert(
        'before-delete' === get_post_meta($post_id, 'hibari_delete_meta', true),
        'dependent PostMeta was not readable before delete'
    );
    hibari_delete_assert(get_comment($comment_id) instanceof WP_Comment, 'dependent Comment was not readable before delete');

    $deleted = wp_delete_post($post_id, true);
    hibari_delete_assert($deleted instanceof WP_Post, 'stock wp_delete_post() did not return the deleted WP_Post');
    hibari_delete_assert($post_id === (int) $deleted->ID, 'wp_delete_post() returned a different identity');

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');
    clean_comment_cache($comment_id);

    hibari_delete_assert(null === get_post($post_id), 'deleted page remained readable from backend state');
    hibari_delete_assert(
        '' === get_post_meta($post_id, 'hibari_delete_meta', true),
        'dependent PostMeta remained after page deletion'
    );
    hibari_delete_assert(null === get_comment($comment_id), 'dependent Comment remained after page deletion');
} catch (\Hibari\WordPress\CompatibilityException $exception) {
    fwrite(STDERR, "Hibari page-delete SQL failure: " . $exception->sql . "\n");
    throw $exception;
}

echo "WordPress page force delete cascade -> Hibari -> KintoneBackend proof: ok\n";
