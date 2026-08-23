<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress post taxonomy-delete proof inputs.\n");
    exit(1);
}

function hibari_post_taxonomy_delete_assert($condition, $message) {
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

hibari_post_taxonomy_delete_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_post_taxonomy_delete_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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
    'wp-includes/class-wp-taxonomy.php',
    'wp-includes/class-wp-term.php',
    'wp-includes/class-wp-term-query.php',
    'wp-includes/class-wp-tax-query.php',
    'wp-includes/taxonomy.php',
    'wp-includes/class-wp-comment.php',
    'wp-includes/comment.php',
);
foreach ($core_files as $core_file) {
    require_once $wordpress_root . '/' . $core_file;
}

wp_installing(true);
create_initial_post_types();

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

hibari_post_taxonomy_delete_assert(taxonomy_exists('category'), 'category taxonomy was not registered');
hibari_post_taxonomy_delete_assert(taxonomy_exists('post_tag'), 'post_tag taxonomy was not registered');

// This proof is about relationship lifecycle, not aggregate term-count maintenance.
wp_defer_term_counting(true);

// Keep revision persistence disabled for this brand-new draft Post.
remove_action('wp_after_insert_post', 'wp_save_post_revision_on_insert', 9);
remove_action('post_updated', 'wp_save_post_revision', 10);
remove_action('post_updated', 'wp_check_for_changed_slugs', 12);
remove_action('post_updated', 'wp_check_for_changed_dates', 12);

// SHORTINIT leaves unrelated post-type integrations registered by
// default-filters.php without loading all dependent query/customizer code.
remove_action('before_delete_post', '_wp_before_delete_font_face', 10);
remove_action('deleted_post', '_wp_after_delete_font_family', 10);
remove_action('delete_post', '_wp_delete_post_menu_item', 10);
remove_action('delete_post', '_wp_delete_customize_changeset_dependent_auto_drafts', 10);

try {
    $post_id = wp_insert_post(
        array(
            'post_author' => 0,
            'post_date' => '2026-08-24 00:00:00',
            'post_date_gmt' => '2026-08-24 00:00:00',
            'post_content' => 'Hibari taxonomy delete body',
            'post_content_filtered' => '',
            'post_title' => 'Hibari taxonomy delete post',
            'post_excerpt' => '',
            'post_status' => 'draft',
            'post_type' => 'post',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => '',
            'to_ping' => '',
            'pinged' => '',
            'post_parent' => 0,
            'menu_order' => 0,
            'post_mime_type' => '',
            'guid' => 'urn:hibari:post:taxonomy-delete',
        ),
        true,
        false
    );
    hibari_post_taxonomy_delete_assert(!is_wp_error($post_id), 'wp_insert_post() returned WP_Error');
    hibari_post_taxonomy_delete_assert(is_int($post_id) && $post_id > 0, 'wp_insert_post() returned no generated Post ID');

    $category = wp_insert_term('Hibari Delete Category', 'category');
    hibari_post_taxonomy_delete_assert(!is_wp_error($category), 'wp_insert_term(category) returned WP_Error');

    $tag = wp_insert_term('Hibari Delete Tag', 'post_tag');
    hibari_post_taxonomy_delete_assert(!is_wp_error($tag), 'wp_insert_term(post_tag) returned WP_Error');

    $category_id = (int) $category['term_id'];
    $category_tt_id = (int) $category['term_taxonomy_id'];
    $tag_id = (int) $tag['term_id'];
    $tag_tt_id = (int) $tag['term_taxonomy_id'];

    $category_set = wp_set_object_terms($post_id, array($category_id), 'category', false);
    hibari_post_taxonomy_delete_assert(!is_wp_error($category_set), 'wp_set_object_terms(category) returned WP_Error');
    hibari_post_taxonomy_delete_assert(
        array($category_tt_id) === array_map('intval', $category_set),
        'category attach returned the wrong TermTaxonomy identity'
    );

    $tag_set = wp_set_object_terms($post_id, array($tag_id), 'post_tag', false);
    hibari_post_taxonomy_delete_assert(!is_wp_error($tag_set), 'wp_set_object_terms(post_tag) returned WP_Error');
    hibari_post_taxonomy_delete_assert(
        array($tag_tt_id) === array_map('intval', $tag_set),
        'tag attach returned the wrong TermTaxonomy identity'
    );

    $category_before = wp_get_object_terms(
        $post_id,
        'category',
        array('fields' => 'ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
    );
    $tag_before = wp_get_object_terms(
        $post_id,
        'post_tag',
        array('fields' => 'ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
    );
    hibari_post_taxonomy_delete_assert(
        array($category_id) === array_map('intval', $category_before),
        'object-scoped category fields=ids projection did not observe membership'
    );
    hibari_post_taxonomy_delete_assert(
        array($tag_id) === array_map('intval', $tag_before),
        'object-scoped post_tag fields=ids projection did not observe membership'
    );

    $deleted = wp_delete_post($post_id, true);
    hibari_post_taxonomy_delete_assert($deleted instanceof WP_Post, 'stock wp_delete_post() did not return the deleted WP_Post');
    hibari_post_taxonomy_delete_assert($post_id === (int) $deleted->ID, 'wp_delete_post() returned a different Post identity');

    clean_post_cache($post_id);

    $category_after = wp_get_object_terms(
        $post_id,
        'category',
        array('fields' => 'ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
    );
    $tag_after = wp_get_object_terms(
        $post_id,
        'post_tag',
        array('fields' => 'ids', 'orderby' => 'none', 'update_term_meta_cache' => false)
    );
    hibari_post_taxonomy_delete_assert(array() === $category_after, 'category membership remained after Post deletion');
    hibari_post_taxonomy_delete_assert(array() === $tag_after, 'post_tag membership remained after Post deletion');
    hibari_post_taxonomy_delete_assert(null === get_post($post_id), 'deleted Post remained readable from backend state');

    $category_survives = term_exists($category_id, 'category');
    $tag_survives = term_exists($tag_id, 'post_tag');
    hibari_post_taxonomy_delete_assert(is_array($category_survives), 'category Term/TermTaxonomy did not survive Post deletion');
    hibari_post_taxonomy_delete_assert(is_array($tag_survives), 'tag Term/TermTaxonomy did not survive Post deletion');
    hibari_post_taxonomy_delete_assert(
        $category_tt_id === (int) $category_survives['term_taxonomy_id'],
        'category TermTaxonomy identity changed after Post deletion'
    );
    hibari_post_taxonomy_delete_assert(
        $tag_tt_id === (int) $tag_survives['term_taxonomy_id'],
        'tag TermTaxonomy identity changed after Post deletion'
    );
} catch (\Hibari\WordPress\CompatibilityException $exception) {
    fwrite(STDERR, "Hibari post taxonomy-delete SQL failure: " . $exception->sql . "\n");
    throw $exception;
}

echo "WordPress post taxonomy force-delete cascade -> Hibari -> KintoneBackend proof: ok\n";
