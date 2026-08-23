<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress metadata-by-mid lifecycle SQL -> Dynamic Attributes IR.
 *
 * Ordinary Metadata API key/value operations stay in MetadataSqlTranslator.
 * This class owns only owner-scoped ID enumeration plus by-mid fetch/delete
 * shapes used by WordPress cascade deletion.
 */
final class MetadataLifecycleSqlTranslator {
    private static function configurations() {
        return array(
            array(
                'tableSuffix' => 'postmeta',
                'model' => 'PostMeta',
                'ownerColumn' => 'post_id',
                'idColumn' => 'meta_id',
                'diagnosticCode' => 'HIB-WP-POSTMETA-001',
                'label' => 'wp_postmeta',
            ),
            array(
                'tableSuffix' => 'commentmeta',
                'model' => 'CommentMeta',
                'ownerColumn' => 'comment_id',
                'idColumn' => 'meta_id',
                'diagnosticCode' => 'HIB-WP-COMMENTMETA-001',
                'label' => 'wp_commentmeta',
            ),
            array(
                'tableSuffix' => 'usermeta',
                'model' => 'UserMeta',
                'ownerColumn' => 'user_id',
                'idColumn' => 'umeta_id',
                'diagnosticCode' => 'HIB-WP-USERMETA-001',
                'label' => 'wp_usermeta',
            ),
        );
    }

    private static function table_pattern($suffix) {
        return '[A-Za-z0-9_]*' . preg_quote($suffix, '/');
    }

    private static function translate_config($normalized, $sql, $config) {
        $table = self::table_pattern($config['tableSuffix']);
        $owner = preg_quote($config['ownerColumn'], '/');
        $id = preg_quote($config['idColumn'], '/');
        $model = $config['model'];

        $pattern = "/^SELECT\\s+`?$id`?\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?$owner`?\\s*=\\s*(\\d+)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => $model,
                    'projection' => array('id'),
                    'filter' => array('op' => 'eq', 'field' => 'ownerId', 'value' => (int) $matches[2]),
                ),
                array('id' => $config['idColumn'])
            );
        }

        $pattern = "/^SELECT\\s+\\*\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?$id`?\\s*=\\s*(\\d+)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => $model,
                    'projection' => array('id', 'ownerId', 'key', 'value'),
                    'filter' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[2]),
                    'limit' => 1,
                ),
                array(
                    'id' => $config['idColumn'],
                    'ownerId' => $config['ownerColumn'],
                    'key' => 'meta_key',
                    'value' => 'meta_value',
                )
            );
        }

        $pattern = "/^DELETE\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?$id`?\\s*=\\s*(\\d+)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            return new SqlTranslation(
                'mutation',
                array(
                    'kind' => 'mutation',
                    'operation' => 'delete',
                    'model' => $model,
                    'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[2]),
                )
            );
        }

        return null;
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');
        foreach (self::configurations() as $config) {
            $result = self::translate_config($normalized, $sql, $config);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }
}
