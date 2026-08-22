<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 wp_postmeta SQL -> backend-neutral IR adapter.
 *
 * This is intentionally not a general EAV/SQL parser. It accepts only the SQL
 * shapes emitted by the Metadata API proof and maps them to the generic
 * PostMeta model used by Hibari's Dynamic Attributes contract.
 */
final class PostmetaSqlTranslator {
    private static function sql_string($value) {
        return str_replace(array("\\'", "\\\\"), array("'", "\\"), $value);
    }

    private static function sql_value($token, $sql) {
        $token = trim($token);
        if (preg_match("/^'((?:\\\\.|[^'])*)'$/s", $token, $matches)) {
            return self::sql_string($matches[1]);
        }
        if (0 === strcasecmp($token, 'NULL')) {
            return null;
        }
        if (is_numeric($token)) {
            return false !== strpos($token, '.') ? (float) $token : (int) $token;
        }

        throw new CompatibilityException(
            'HIB-WP-POSTMETA-001',
            'Unsupported wp_postmeta literal in the proven WordPress subset.',
            'wordpress.dynamicAttributes',
            $sql
        );
    }

    private static function and_filter($filters) {
        return 1 === count($filters)
            ? $filters[0]
            : array('op' => 'and', 'expressions' => array_values($filters));
    }

    private static function owner_key_filter($post_id, $meta_key, $meta_value = null, $include_value = false) {
        $filters = array(
            array('op' => 'eq', 'field' => 'ownerId', 'value' => (int) $post_id),
            array('op' => 'eq', 'field' => 'key', 'value' => $meta_key),
        );
        if ($include_value) {
            $filters[] = array('op' => 'eq', 'field' => 'value', 'value' => $meta_value);
        }
        return self::and_filter($filters);
    }

    private static function existence_count($normalized, $sql) {
        $pattern = "/^SELECT\\s+COUNT\\(\\*\\)\\s+FROM\\s+`?([A-Za-z0-9_]*postmeta)`?\\s+WHERE\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+AND\\s+`?post_id`?\\s*=\\s*(\\d+)$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'PostMeta',
                'projection' => array('id'),
                'filter' => self::owner_key_filter((int) $matches[3], self::sql_value($matches[2], $sql)),
                'limit' => 1,
            ),
            array('id' => 'COUNT(*)')
        );
    }

    private static function cache_select($normalized, $sql) {
        $pattern = '/^SELECT\\s+`?post_id`?\\s*,\\s*`?meta_key`?\\s*,\\s*`?meta_value`?\\s+FROM\\s+`?([A-Za-z0-9_]*postmeta)`?\\s+WHERE\\s+`?post_id`?\\s+IN\\s*\\(([^)]*)\\)\\s+ORDER\\s+BY\\s+`?meta_id`?\\s+ASC$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $ids = array();
        foreach (explode(',', $matches[2]) as $token) {
            $value = self::sql_value($token, $sql);
            if (!is_int($value) && !is_float($value)) {
                throw new CompatibilityException('HIB-WP-POSTMETA-001', 'wp_postmeta owner IDs must be numeric.', 'wordpress.dynamicAttributes', $sql);
            }
            $ids[] = (int) $value;
        }

        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'PostMeta',
                'projection' => array('ownerId', 'key', 'value'),
                'filter' => array('op' => 'in', 'field' => 'ownerId', 'values' => $ids),
                'ordering' => array(array('field' => 'id', 'direction' => 'asc')),
            ),
            array(
                'ownerId' => 'post_id',
                'key' => 'meta_key',
                'value' => 'meta_value',
            )
        );
    }

    private static function select_ids($normalized, $sql) {
        $pattern = "/^SELECT\\s+`?meta_id`?\\s+FROM\\s+`?([A-Za-z0-9_]*postmeta)`?\\s+WHERE\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+AND\\s+`?post_id`?\\s*=\\s*(\\d+)(?:\\s+AND\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*'))?$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $include_value = isset($matches[4]) && '' !== $matches[4];
        $value = $include_value ? self::sql_value($matches[4], $sql) : null;
        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'PostMeta',
                'projection' => array('id'),
                'filter' => self::owner_key_filter(
                    (int) $matches[3],
                    self::sql_value($matches[2], $sql),
                    $value,
                    $include_value
                ),
            ),
            array('id' => 'meta_id')
        );
    }

    private static function insert($normalized, $sql) {
        $pattern = "/^INSERT\\s+INTO\\s+`?([A-Za-z0-9_]*postmeta)`?\\s*\\(\\s*`?post_id`?\\s*,\\s*`?meta_key`?\\s*,\\s*`?meta_value`?\\s*\\)\\s+VALUES\\s*\\(\\s*(\\d+)\\s*,\\s*('(?:\\\\.|[^'])*')\\s*,\\s*('(?:\\\\.|[^'])*')\\s*\\)$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'insert',
                'model' => 'PostMeta',
                'record' => array(
                    'ownerId' => (int) $matches[2],
                    'key' => self::sql_value($matches[3], $sql),
                    'value' => self::sql_value($matches[4], $sql),
                ),
            )
        );
    }

    private static function update($normalized, $sql) {
        $pattern = "/^UPDATE\\s+`?([A-Za-z0-9_]*postmeta)`?\\s+SET\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+WHERE\\s+`?post_id`?\\s*=\\s*(\\d+)\\s+AND\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')(?:\\s+AND\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*'))?$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $include_previous = isset($matches[5]) && '' !== $matches[5];
        $previous = $include_previous ? self::sql_value($matches[5], $sql) : null;
        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'updateMany',
                'model' => 'PostMeta',
                'where' => self::owner_key_filter(
                    (int) $matches[3],
                    self::sql_value($matches[4], $sql),
                    $previous,
                    $include_previous
                ),
                'changes' => array('value' => self::sql_value($matches[2], $sql)),
            )
        );
    }

    private static function delete_by_ids($normalized, $sql) {
        $pattern = '/^DELETE\\s+FROM\\s+`?([A-Za-z0-9_]*postmeta)`?\\s+WHERE\\s+`?meta_id`?\\s+IN\\s*\\(\\s*([^)]*)\\s*\\)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $ids = array();
        foreach (explode(',', $matches[2]) as $token) {
            $value = self::sql_value($token, $sql);
            if (!is_int($value) && !is_float($value)) {
                throw new CompatibilityException('HIB-WP-POSTMETA-001', 'wp_postmeta IDs must be numeric.', 'wordpress.dynamicAttributes', $sql);
            }
            $ids[] = (int) $value;
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'delete',
                'model' => 'PostMeta',
                'where' => array('op' => 'in', 'field' => 'id', 'values' => $ids),
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');

        foreach (array(
            'existence_count',
            'cache_select',
            'select_ids',
            'insert',
            'update',
            'delete_by_ids',
        ) as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }
}
