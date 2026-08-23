<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$expected_theme = isset($argv[3]) ? $argv[3] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (
    false === $wordpress_root
    || false === $package_root
    || false === $bridge_bootstrap
    || '' === $runtime_url
    || '' === $expected_theme
) {
    fwrite(STDERR, "Unable to resolve WordPress full-bootstrap proof inputs.\n");
    exit(1);
}

function hibari_full_bootstrap_assert($condition, $message) {
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
define('WP_DEBUG', false);
define('DISABLE_WP_CRON', true);
define('AUTH_KEY', 'hibari-auth-key');
define('SECURE_AUTH_KEY', 'hibari-secure-auth-key');
define('LOGGED_IN_KEY', 'hibari-logged-in-key');
define('NONCE_KEY', 'hibari-nonce-key');
define('AUTH_SALT', 'hibari-auth-salt');
define('SECURE_AUTH_SALT', 'hibari-secure-auth-salt');
define('LOGGED_IN_SALT', 'hibari-logged-in-salt');
define('NONCE_SALT', 'hibari-nonce-salt');
PHP;

$config .= "\ndefine('HIBARI_WORDPRESS_ROOT', " . var_export($package_root, true) . ");\n";
$config .= "define('HIBARI_WORDPRESS_BRIDGE_BOOTSTRAP', " . var_export($bridge_bootstrap, true) . ");\n";
$config .= "define('HIBARI_RUNTIME_HTTP_URL', " . var_export($runtime_url, true) . ");\n";
$config .= "\$table_prefix = 'wp_';\n";
$config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
$config .= "require_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($wordpress_root . '/wp-config.php', $config);

try {
    require $wordpress_root . '/wp-load.php';
} catch (\Hibari\WordPress\CompatibilityException $exception) {
    fwrite(STDERR, "Hibari full-bootstrap SQL failure: " . $exception->sql . "\n");
    throw $exception;
}

hibari_full_bootstrap_assert(
    !defined('SHORTINIT'),
    'full-bootstrap proof unexpectedly defined SHORTINIT'
);
hibari_full_bootstrap_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'normal bootstrap did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_full_bootstrap_assert(
    false === $GLOBALS['wpdb']->is_mysql,
    'Hibari wpdb opened or advertised MySQL during normal bootstrap'
);
hibari_full_bootstrap_assert(
    did_action('wp_loaded') > 0,
    'normal WordPress bootstrap did not reach wp_loaded'
);

hibari_full_bootstrap_assert(
    $expected_theme === get_option('template'),
    'normal bootstrap did not read the selected template from Hibari-backed Options'
);
hibari_full_bootstrap_assert(
    $expected_theme === get_option('stylesheet'),
    'normal bootstrap did not read the selected stylesheet from Hibari-backed Options'
);

$theme = wp_get_theme();
hibari_full_bootstrap_assert($theme instanceof WP_Theme, 'wp_get_theme() did not return WP_Theme');
hibari_full_bootstrap_assert($theme->exists(), 'selected bundled stock theme does not exist');
hibari_full_bootstrap_assert(
    $expected_theme === $theme->get_stylesheet(),
    'wp_get_theme() resolved a different stylesheet than the Hibari-backed option'
);
hibari_full_bootstrap_assert(
    $expected_theme === basename(get_template_directory()),
    'get_template_directory() did not resolve the selected bundled stock theme'
);

$siteurl = get_option('siteurl');
hibari_full_bootstrap_assert(
    'https://example.test' === $siteurl,
    'siteurl was not read from Hibari-backed Options during normal bootstrap'
);

echo "WordPress full bootstrap + bundled theme -> Hibari -> KintoneBackend proof: ok\n";
echo "theme: " . $theme->get_stylesheet() . "\n";
