<?php

require_once HIBARI_WORDPRESS_ROOT . '/src/WordPressSqlTranslator.php';
require_once HIBARI_WORDPRESS_ROOT . '/src/OptionPreloadSqlTranslator.php';
require_once HIBARI_WORDPRESS_ROOT . '/src/PostmetaSqlTranslator.php';
require_once HIBARI_WORDPRESS_ROOT . '/src/TaxonomySqlTranslator.php';
require_once HIBARI_WORDPRESS_ROOT . '/src/HttpBridge.php';

$GLOBALS['hibari_wordpress_bridge_factory'] = function () {
    if (!defined('HIBARI_RUNTIME_HTTP_URL')) {
        throw new RuntimeException('HIBARI_RUNTIME_HTTP_URL is required for the HTTP bridge proof.');
    }
    return new \Hibari\WordPress\HttpBridge(HIBARI_RUNTIME_HTTP_URL);
};
