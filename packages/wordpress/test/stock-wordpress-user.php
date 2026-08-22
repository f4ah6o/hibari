<?php

$wordpress_root = isset($argv[1]) ? realpath($argv[1]) : false;
$runtime_url = isset($argv[2]) ? $argv[2] : '';
$package_root = realpath(dirname(__DIR__));
$bridge_bootstrap = realpath(__DIR__ . '/http-bridge-bootstrap.php');

if (false === $wordpress_root || false === $package_root || false === $bridge_bootstrap || '' === $runtime_url) {
    fwrite(STDERR, "Unable to resolve WordPress user proof inputs.\n");
    exit(1);
}

function hibari_user_assert($condition, $message) {
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

hibari_user_assert(
    $GLOBALS['wpdb'] instanceof \Hibari\WordPress\HibariWpdb,
    'stock WordPress did not retain the Hibari HTTP-backed wpdb instance'
);
hibari_user_assert(false === $GLOBALS['wpdb']->is_mysql, 'Hibari wpdb opened or advertised MySQL');

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
require_once $wordpress_root . '/wp-includes/pluggable.php';

wp_installing(true);

$plain_password = 'HibariUserProofPlaintext-DoNotLog-2026!';
$user_id = wp_insert_user(
    array(
        'user_login' => 'hibari_user',
        'user_pass' => $plain_password,
        'user_email' => 'hibari.user@example.test',
        'user_nicename' => 'hibari-user',
        'display_name' => 'Hibari User',
        'nickname' => 'Hibari Nick',
        'first_name' => 'Hibari',
        'last_name' => 'User',
    )
);

hibari_user_assert(!is_wp_error($user_id), 'stock wp_insert_user() returned WP_Error');
hibari_user_assert(is_int($user_id) && $user_id > 0, 'created user did not receive an integer identity');

clean_user_cache($user_id);
$by_id = get_user_by('id', $user_id);
hibari_user_assert($by_id instanceof WP_User, 'get_user_by(id) did not observe backend state');
hibari_user_assert('hibari_user' === $by_id->user_login, 'created login was not preserved');
hibari_user_assert('hibari.user@example.test' === $by_id->user_email, 'created email was not preserved');
hibari_user_assert('Hibari User' === $by_id->display_name, 'created display name was not preserved');
hibari_user_assert(is_string($by_id->user_pass) && '' !== $by_id->user_pass, 'WordPress password hash was not persisted');
hibari_user_assert($plain_password !== $by_id->user_pass, 'plain-text password reached persisted User state');
$stored_hash = $by_id->user_pass;

clean_user_cache($user_id);
$by_login = get_user_by('login', 'hibari_user');
hibari_user_assert($by_login instanceof WP_User && $user_id === (int) $by_login->ID, 'login lookup changed user identity');

clean_user_cache($user_id);
$by_email = get_user_by('email', 'hibari.user@example.test');
hibari_user_assert($by_email instanceof WP_User && $user_id === (int) $by_email->ID, 'email lookup changed user identity');

wp_cache_delete($user_id, 'user_meta');
$nickname = get_user_meta($user_id, 'nickname', true);
hibari_user_assert('Hibari Nick' === $nickname, 'default user metadata did not round-trip through Dynamic Attributes');

$updated_id = wp_update_user(
    array(
        'ID' => $user_id,
        'display_name' => 'Hibari User Updated',
    )
);
hibari_user_assert(!is_wp_error($updated_id), 'stock wp_update_user() returned WP_Error');
hibari_user_assert($user_id === (int) $updated_id, 'wp_update_user() changed user identity');

clean_user_cache($user_id);
$updated = get_user_by('id', $user_id);
hibari_user_assert($updated instanceof WP_User, 'updated user was not readable');
hibari_user_assert('Hibari User Updated' === $updated->display_name, 'display-name update did not persist');
hibari_user_assert($stored_hash === $updated->user_pass, 'basic update did not preserve the opaque WordPress password hash');

$duplicate_login = wp_insert_user(
    array(
        'user_login' => 'hibari_user',
        'user_pass' => 'DuplicateLoginProofPlaintext-DoNotLog!',
        'user_email' => 'other@example.test',
    )
);
hibari_user_assert(is_wp_error($duplicate_login), 'duplicate login unexpectedly created a user');
hibari_user_assert('existing_user_login' === $duplicate_login->get_error_code(), 'duplicate login did not return existing_user_login');

$duplicate_email = wp_insert_user(
    array(
        'user_login' => 'hibari_user_two',
        'user_pass' => 'DuplicateEmailProofPlaintext-DoNotLog!',
        'user_email' => 'hibari.user@example.test',
    )
);
hibari_user_assert(is_wp_error($duplicate_email), 'duplicate email unexpectedly created a user');
hibari_user_assert('existing_user_email' === $duplicate_email->get_error_code(), 'duplicate email did not return existing_user_email');

clean_user_cache($user_id);
$after_duplicates = get_user_by('id', $user_id);
hibari_user_assert($after_duplicates instanceof WP_User, 'original user disappeared after duplicate rejection');
hibari_user_assert('Hibari User Updated' === $after_duplicates->display_name, 'duplicate rejection changed original user');

echo "WordPress user entity + Dynamic Attributes -> Hibari -> KintoneBackend proof: ok\n";
