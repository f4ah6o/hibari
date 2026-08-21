<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress runtime proof inputs.\n");
    exit(1);
}

function hibari_runtime_assert($condition, $message) {
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

hibari_runtime_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_runtime_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

require_once $wordpress_root . '/wp-includes/option.php';
wp_installing(true);

$value = get_option('siteurl');
hibari_runtime_assert(
    'https://kintone-backed.example.test' === $value,
    'stock get_option() did not return data from the Kintone-backed Hibari runtime'
);

$caught = false;
try {
    $GLOBALS['wpdb']->query('SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID');
} catch (\Hibari\WordPress\CompatibilityException $error) {
    $caught = true;
    hibari_runtime_assert('HIB-WP-JOIN-001' === $error->diagnostic_code, 'JOIN diagnostic code changed');
}
hibari_runtime_assert($caught, 'unsupported JOIN was not rejected before runtime transport');

echo "WordPress -> Hibari runtime -> KintoneBackend proof: ok\n";
