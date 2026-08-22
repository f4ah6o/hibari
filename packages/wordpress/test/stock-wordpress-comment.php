<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress comment proof inputs.\n");
    exit(1);
}

function hibari_comment_assert($condition, $message) {
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

hibari_comment_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_comment_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

$core_files = array(
    'wp-includes/l10n.php',
    'wp-includes/capabilities.php',
    'wp-includes/class-wp-roles.php',
    'wp-includes/class-wp-role.php',
    'wp-includes/class-wp-user.php',
    'wp-includes/user.php',
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
    'wp-includes/class-wp-comment.php',
    'wp-includes/comment.php',
);
foreach ($core_files as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}
$GLOBALS['current_user'] = new WP_User(0);
require_once $wordpress_root . '/wp-includes/pluggable.php';

wp_installing(true);
// Comment-count maintenance is an aggregate side domain. Defer it for this
// focused Comment/CommentMeta proof and do not flush the queue here.
wp_defer_comment_counting(true);

$comment_id = wp_insert_comment(
    array(
        'comment_post_ID' => 42,
        'comment_author' => 'Hibari Commenter',
        'comment_author_email' => 'commenter@example.test',
        'comment_author_url' => 'https://example.test/commenter',
        'comment_author_IP' => '192.0.2.42',
        'comment_date' => '2026-08-22 00:00:00',
        'comment_date_gmt' => '2026-08-22 00:00:00',
        'comment_content' => 'Initial Hibari comment',
        'comment_karma' => 0,
        'comment_approved' => 0,
        'comment_agent' => 'hibari-proof',
        'comment_type' => 'comment',
        'comment_parent' => 0,
        'user_id' => 0,
        'comment_meta' => array(
            'proof_key' => 'initial-meta',
        ),
    )
);

hibari_comment_assert(is_int($comment_id) && $comment_id > 0, 'wp_insert_comment() did not return a generated integer ID');

clean_comment_cache($comment_id);
$created = get_comment($comment_id);
hibari_comment_assert($created instanceof WP_Comment, 'get_comment() did not observe created backend state');
hibari_comment_assert(42 === (int) $created->comment_post_ID, 'created comment post ID changed');
hibari_comment_assert('Hibari Commenter' === $created->comment_author, 'created comment author changed');
hibari_comment_assert('commenter@example.test' === $created->comment_author_email, 'created comment email changed');
hibari_comment_assert('Initial Hibari comment' === $created->comment_content, 'created comment content changed');
hibari_comment_assert('0' === (string) $created->comment_approved, 'created comment approval state changed');

wp_cache_delete($comment_id, 'comment_meta');
$initial_meta = get_comment_meta($comment_id, 'proof_key', true);
hibari_comment_assert('initial-meta' === $initial_meta, 'initial comment metadata did not round-trip');

$update_result = wp_update_comment(
    array(
        'comment_ID' => $comment_id,
        'comment_content' => 'Updated Hibari comment',
        'comment_meta' => array(
            'proof_key' => 'updated-meta',
        ),
    ),
    true
);
hibari_comment_assert(!is_wp_error($update_result) && false !== $update_result, 'wp_update_comment() failed');

clean_comment_cache($comment_id);
$updated = get_comment($comment_id);
hibari_comment_assert($updated instanceof WP_Comment, 'updated comment was not readable');
hibari_comment_assert($comment_id === (int) $updated->comment_ID, 'comment identity changed during update');
hibari_comment_assert(42 === (int) $updated->comment_post_ID, 'comment post ID changed during update');
hibari_comment_assert('Updated Hibari comment' === $updated->comment_content, 'comment content update did not persist');
hibari_comment_assert('0' === (string) $updated->comment_approved, 'comment approval state changed during basic update');

wp_cache_delete($comment_id, 'comment_meta');
$updated_meta = get_comment_meta($comment_id, 'proof_key', true);
hibari_comment_assert('updated-meta' === $updated_meta, 'updated comment metadata did not round-trip');

echo "WordPress Comment + CommentMeta Dynamic Attributes -> Hibari -> KintoneBackend proof: ok\n";
