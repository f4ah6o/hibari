<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress Metadata API SQL -> Hibari Dynamic Attributes IR.
 *
 * The table suffix, owner/id columns, and backend-neutral model are supplied by
 * the consumer wrapper. Core continues to see only id / ownerId / key / value.
 */
final class MetadataSqlTranslator {
    private static function sql_string($value) {
        return str_replace(array("\\'", "\\\\"), array("'", "\\"), $value);
    }

    private static function sql_value($token, $sql, $code, $label) {
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
            $code,
            'Unsupported ' . $label . ' literal in the proven WordPress subset.',
            'wordpress.dynamicAttributes',
            $sql
        );
    }

    private static function and_filter($filters) {
        return 1 === count($filters)
            ? $filters[0]
            : array('op' => 'and', 'expressions' => array_values($filters));
    }

    private static function owner_key_filter($owner_id, $meta_key, $meta_value = null, $include_value = false) {
        $filters = array(
            array('op' => 'eq', 'field' => 'ownerId', 'value' => (int) $owner_id),
            array('op' => 'eq', 'field' => 'key', 'value' => $meta_key),
        );
        if ($include_value) {
            $filters[] = array('op' => 'eq', 'field' => 'value', 'value' => $meta_value);
        }
        return self::and_filter($filters);
    }

    private static function config($config) {
        foreach (array('tableSuffix', 'model', 'ownerColumn', 'idColumn', 'diagnosticCode', 'label') as $key) {
            if (!isset($config[$key]) || '' === (string) $config[$key]) {
                throw new \InvalidArgumentException('Metadata translator config requires ' . $key . '.');
            }
        }
        return $config;
    }

    private static function table_pattern($suffix) {
        return '[A-Za-z0-9_]*' . preg_quote($suffix, '/');
    }

    public static function translate($sql, $configuration) {
        $config = self::config($configuration);
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');
        $table = self::table_pattern($config['tableSuffix']);
        $owner = preg_quote($config['ownerColumn'], '/');
        $id = preg_quote($config['idColumn'], '/');
        $model = $config['model'];
        $code = $config['diagnosticCode'];
        $label = $config['label'];

        $pattern = "/^SELECT\\s+COUNT\\(\\*\\)\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+AND\\s+`?$owner`?\\s*=\\s*(\\d+)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => $model,
                    'projection' => array('id'),
                    'filter' => self::owner_key_filter(
                        (int) $matches[3],
                        self::sql_value($matches[2], $sql, $code, $label)
                    ),
                    'limit' => 1,
                ),
                array('id' => 'COUNT(*)')
            );
        }

        $pattern = "/^SELECT\\s+`?$owner`?\\s*,\\s*`?meta_key`?\\s*,\\s*`?meta_value`?\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?$owner`?\\s+IN\\s*\\(([^)]*)\\)\\s+ORDER\\s+BY\\s+`?$id`?\\s+ASC$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            $ids = array();
            foreach (explode(',', $matches[2]) as $token) {
                $value = self::sql_value($token, $sql, $code, $label);
                if (!is_int($value) && !is_float($value)) {
                    throw new CompatibilityException($code, $label . ' owner IDs must be numeric.', 'wordpress.dynamicAttributes', $sql);
                }
                $ids[] = (int) $value;
            }
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => $model,
                    'projection' => array('ownerId', 'key', 'value'),
                    'filter' => array('op' => 'in', 'field' => 'ownerId', 'values' => $ids),
                    'ordering' => array(array('field' => 'id', 'direction' => 'asc')),
                ),
                array(
                    'ownerId' => $config['ownerColumn'],
                    'key' => 'meta_key',
                    'value' => 'meta_value',
                )
            );
        }

        $pattern = "/^SELECT\\s+`?$id`?\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+AND\\s+`?$owner`?\\s*=\\s*(\\d+)(?:\\s+AND\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*'))?$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            $include_value = isset($matches[4]) && '' !== $matches[4];
            $value = $include_value ? self::sql_value($matches[4], $sql, $code, $label) : null;
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => $model,
                    'projection' => array('id'),
                    'filter' => self::owner_key_filter(
                        (int) $matches[3],
                        self::sql_value($matches[2], $sql, $code, $label),
                        $value,
                        $include_value
                    ),
                ),
                array('id' => $config['idColumn'])
            );
        }

        $pattern = "/^INSERT\\s+INTO\\s+`?($table)`?\\s*\\(\\s*`?$owner`?\\s*,\\s*`?meta_key`?\\s*,\\s*`?meta_value`?\\s*\\)\\s+VALUES\\s*\\(\\s*(\\d+|'\\d+')\\s*,\\s*('(?:\\\\.|[^'])*')\\s*,\\s*('(?:\\\\.|[^'])*')\\s*\\)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            $owner_value = self::sql_value($matches[2], $sql, $code, $label);
            if (!is_numeric($owner_value)) {
                throw new CompatibilityException($code, $label . ' owner ID must be numeric.', 'wordpress.dynamicAttributes', $sql);
            }
            return new SqlTranslation(
                'mutation',
                array(
                    'kind' => 'mutation',
                    'operation' => 'insert',
                    'model' => $model,
                    'record' => array(
                        'ownerId' => (int) $owner_value,
                        'key' => self::sql_value($matches[3], $sql, $code, $label),
                        'value' => self::sql_value($matches[4], $sql, $code, $label),
                    ),
                )
            );
        }

        $pattern = "/^UPDATE\\s+`?($table)`?\\s+SET\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+WHERE\\s+`?$owner`?\\s*=\\s*(\\d+|'\\d+')\\s+AND\\s+`?meta_key`?\\s*=\\s*('(?:\\\\.|[^'])*')(?:\\s+AND\\s+`?meta_value`?\\s*=\\s*('(?:\\\\.|[^'])*'))?$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            $owner_value = self::sql_value($matches[3], $sql, $code, $label);
            if (!is_numeric($owner_value)) {
                throw new CompatibilityException($code, $label . ' owner ID must be numeric.', 'wordpress.dynamicAttributes', $sql);
            }
            $include_previous = isset($matches[5]) && '' !== $matches[5];
            $previous = $include_previous ? self::sql_value($matches[5], $sql, $code, $label) : null;
            return new SqlTranslation(
                'mutation',
                array(
                    'kind' => 'mutation',
                    'operation' => 'updateMany',
                    'model' => $model,
                    'where' => self::owner_key_filter(
                        (int) $owner_value,
                        self::sql_value($matches[4], $sql, $code, $label),
                        $previous,
                        $include_previous
                    ),
                    'changes' => array('value' => self::sql_value($matches[2], $sql, $code, $label)),
                )
            );
        }

        $pattern = "/^DELETE\\s+FROM\\s+`?($table)`?\\s+WHERE\\s+`?$id`?\\s+IN\\s*\\(\\s*([^)]*)\\s*\\)$/i";
        if (preg_match($pattern, $normalized, $matches)) {
            $ids = array();
            foreach (explode(',', $matches[2]) as $token) {
                $value = self::sql_value($token, $sql, $code, $label);
                if (!is_int($value) && !is_float($value)) {
                    throw new CompatibilityException($code, $label . ' IDs must be numeric.', 'wordpress.dynamicAttributes', $sql);
                }
                $ids[] = (int) $value;
            }
            return new SqlTranslation(
                'mutation',
                array(
                    'kind' => 'mutation',
                    'operation' => 'delete',
                    'model' => $model,
                    'where' => array('op' => 'in', 'field' => 'id', 'values' => $ids),
                )
            );
        }

        return null;
    }
}
