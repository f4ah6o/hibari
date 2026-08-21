<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress options proof inputs.\n");
    exit(1);
}

function hibari_options_assert($condition, $message) {
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

hibari_options_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_options_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

require_once $wordpress_root . '/wp-includes/option.php';
wp_installing(true);

hibari_options_assert(
    'before' === get_option('hibari_existing'),
    'seeded option was not read through KintoneBackend'
);

hibari_options_assert(
    true === update_option('hibari_existing', 'after', false),
    'stock update_option() did not report a persisted change'
);
hibari_options_assert(
    'after' === get_option('hibari_existing'),
    'updated option value was not persisted through KintoneBackend'
);

hibari_options_assert(
    true === add_option('hibari_added', 'created', '', false),
    'stock add_option() did not create a new option'
);
hibari_options_assert(
    'created' === get_option('hibari_added'),
    'new option was not persisted through KintoneBackend'
);

hibari_options_assert(
    true === delete_option('hibari_added'),
    'stock delete_option() did not delete the new option'
);
hibari_options_assert(
    'missing' === get_option('hibari_added', 'missing'),
    'deleted option remained visible through KintoneBackend'
);

echo "WordPress options CRUD -> Hibari -> KintoneBackend proof: ok\n";
