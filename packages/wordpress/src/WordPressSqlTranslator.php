<?php

namespace Hibari\WordPress;

final class SqlTranslation {
    /** @var string */
    public $endpoint;

    /** @var array<string, mixed> */
    public $operation;

    /** @var array<string, string> application field => SQL output column */
    public $columns;

    public function __construct($endpoint, $operation, $columns = array()) {
        $this->endpoint = $endpoint;
        $this->operation = $operation;
        $this->columns = $columns;
    }
}

final class WordPressSqlTranslator {
    private static $option_fields = array(
        'option_name' => 'name',
        'option_value' => 'value',
        'autoload' => 'autoload',
    );

    private static $post_fields = array(
        'id' => 'id',
        'post_author' => 'authorId',
        'post_date' => 'date',
        'post_date_gmt' => 'dateGmt',
        'post_content' => 'content',
        'post_title' => 'title',
        'post_excerpt' => 'excerpt',
        'post_status' => 'status',
        'comment_status' => 'commentStatus',
        'ping_status' => 'pingStatus',
        'post_password' => 'password',
        'post_name' => 'slug',
        'to_ping' => 'toPing',
        'pinged' => 'pinged',
        'post_modified' => 'modified',
        'post_modified_gmt' => 'modifiedGmt',
        'post_content_filtered' => 'contentFiltered',
        'post_parent' => 'parentId',
        'guid' => 'guid',
        'menu_order' => 'menuOrder',
        'post_type' => 'type',
        'post_mime_type' => 'mimeType',
        'comment_count' => 'commentCount',
    );

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
            'HIB-WP-SQL-001',
            'Unsupported SQL literal in WordPress statement.',
            'wordpress.sql.translation',
            $sql
        );
    }

    private static function split_list($text) {
        $items = array();
        $current = '';
        $quoted = false;
        $escaped = false;
        $depth = 0;
        $length = strlen($text);

        for ($index = 0; $index < $length; ++$index) {
            $char = $text[$index];
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            if ($quoted && '\\' === $char) {
                $current .= $char;
                $escaped = true;
                continue;
            }
            if ("'" === $char) {
                $quoted = !$quoted;
                $current .= $char;
                continue;
            }
            if (!$quoted) {
                if ('(' === $char) {
                    ++$depth;
                } elseif (')' === $char && $depth > 0) {
                    --$depth;
                } elseif (',' === $char && 0 === $depth) {
                    $items[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            $current .= $char;
        }

        if ('' !== trim($current)) {
            $items[] = trim($current);
        }
        return $items;
    }

    private static function option_field($column, $sql) {
        $column = strtolower(trim($column, " `\t\n\r\0\x0B"));
        if (!isset(self::$option_fields[$column])) {
            throw new CompatibilityException(
                'HIB-WP-SQL-001',
                'Unsupported wp_options column in Hibari WordPress translation.',
                'wordpress.sql.translation',
                $sql
            );
        }
        return self::$option_fields[$column];
    }

    private static function post_field($column, $sql) {
        $column = strtolower(trim($column, " `\t\n\r\0\x0B"));
        if (!isset(self::$post_fields[$column])) {
            throw new CompatibilityException(
                'HIB-WP-SQL-001',
                'Unsupported wp_posts column in Hibari WordPress translation.',
                'wordpress.sql.translation',
                $sql
            );
        }
        return self::$post_fields[$column];
    }

    private static function option_select($normalized, $sql) {
        $pattern = "/^SELECT\\s+`?([A-Za-z0-9_]+)`?\\s+FROM\\s+`?([A-Za-z0-9_]*options)`?\\s+WHERE\\s+`?option_name`?\\s*=\\s*('(?:\\\\.|[^'])*')(?:\\s+LIMIT\\s+1)?$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $column = strtolower($matches[1]);
        $field = self::option_field($column, $sql);
        $name = self::sql_value($matches[3], $sql);
        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'Option',
                'projection' => array($field),
                'filter' => array('op' => 'eq', 'field' => 'name', 'value' => $name),
                'limit' => 1,
            ),
            array($field => $column)
        );
    }

    private static function option_select_names($normalized, $sql) {
        $pattern = "/^SELECT\\s+`?option_name`?\\s*,\\s*`?option_value`?\\s+FROM\\s+`?([A-Za-z0-9_]*options)`?\\s+WHERE\\s+`?option_name`?\\s+IN\\s*\\((.+)\\)$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $names = array();
        foreach (self::split_list($matches[2]) as $token) {
            $name = self::sql_value($token, $sql);
            if (!is_string($name)) {
                throw new CompatibilityException(
                    'HIB-WP-SQL-001',
                    'wp_options cache-prime lookup requires string option names.',
                    'wordpress.sql.translation',
                    $sql
                );
            }
            $names[] = $name;
        }
        if (empty($names)) {
            return null;
        }

        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'Option',
                'projection' => array('name', 'value'),
                'filter' => array('op' => 'in', 'field' => 'name', 'values' => $names),
            ),
            array('name' => 'option_name', 'value' => 'option_value')
        );
    }

    private static function option_update($normalized, $sql) {
        $pattern = "/^UPDATE\\s+`?([A-Za-z0-9_]*options)`?\\s+SET\\s+(.+)\\s+WHERE\\s+`?option_name`?\\s*=\\s*('(?:\\\\.|[^'])*')$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $changes = array();
        foreach (self::split_list($matches[2]) as $assignment) {
            if (!preg_match('/^`?([A-Za-z0-9_]+)`?\\s*=\\s*(.+)$/s', $assignment, $parts)) {
                throw new CompatibilityException('HIB-WP-SQL-001', 'Unsupported wp_options UPDATE assignment.', 'wordpress.sql.translation', $sql);
            }
            $field = self::option_field($parts[1], $sql);
            if ('name' === $field) {
                throw new CompatibilityException('HIB-WP-SQL-001', 'Renaming WordPress options is not supported.', 'wordpress.sql.translation', $sql);
            }
            $changes[$field] = self::sql_value($parts[2], $sql);
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'update',
                'model' => 'Option',
                'where' => array('op' => 'eq', 'field' => 'name', 'value' => self::sql_value($matches[3], $sql)),
                'changes' => $changes,
            )
        );
    }

    private static function option_delete($normalized, $sql) {
        $pattern = "/^DELETE\\s+FROM\\s+`?([A-Za-z0-9_]*options)`?\\s+WHERE\\s+`?option_name`?\\s*=\\s*('(?:\\\\.|[^'])*')$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'delete',
                'model' => 'Option',
                'where' => array('op' => 'eq', 'field' => 'name', 'value' => self::sql_value($matches[2], $sql)),
            )
        );
    }

    private static function option_insert_upsert($normalized, $sql) {
        $pattern = '/^INSERT\\s+INTO\\s+`?([A-Za-z0-9_]*options)`?\\s*\\((.+)\\)\\s+VALUES\\s*\\((.+)\\)\\s+ON\\s+DUPLICATE\\s+KEY\\s+UPDATE\\s+.+$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $columns = self::split_list($matches[2]);
        $values = self::split_list($matches[3]);
        if (count($columns) !== count($values)) {
            throw new CompatibilityException('HIB-WP-SQL-001', 'wp_options INSERT column/value count mismatch.', 'wordpress.sql.translation', $sql);
        }

        $record = array();
        foreach ($columns as $index => $column) {
            $field = self::option_field($column, $sql);
            $record[$field] = self::sql_value($values[$index], $sql);
        }
        if (!isset($record['name'])) {
            throw new CompatibilityException('HIB-WP-SQL-001', 'wp_options INSERT requires option_name.', 'wordpress.sql.translation', $sql);
        }

        $update = $record;
        unset($update['name']);
        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'upsert',
                'model' => 'Option',
                'where' => array('op' => 'eq', 'field' => 'name', 'value' => $record['name']),
                'create' => $record,
                'update' => $update,
            )
        );
    }

    private static function post_select_by_id($normalized, $sql) {
        $pattern = '/^SELECT\\s+(\\*|`?[A-Za-z0-9_]+`?)\\s+FROM\\s+`?([A-Za-z0-9_]*posts)`?\\s+WHERE\\s+`?ID`?\\s*=\\s*([^\\s]+)(?:\\s+LIMIT\\s+1)?$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $id = self::sql_value($matches[3], $sql);
        if (!is_int($id) && !is_float($id)) {
            throw new CompatibilityException('HIB-WP-SQL-001', 'wp_posts ID lookup requires a numeric ID.', 'wordpress.sql.translation', $sql);
        }

        $columns = array();
        $projection = null;
        if ('*' === $matches[1]) {
            foreach (self::$post_fields as $column => $field) {
                $columns[$field] = 'id' === $column ? 'ID' : $column;
            }
        } else {
            $column = trim($matches[1], '`');
            $field = self::post_field($column, $sql);
            $projection = array($field);
            $columns[$field] = 0 === strcasecmp($column, 'ID') ? 'ID' : strtolower($column);
        }

        $operation = array(
            'kind' => 'query',
            'model' => 'Post',
            'filter' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $id),
            'limit' => 1,
        );
        if (null !== $projection) {
            $operation['projection'] = $projection;
        }

        return new SqlTranslation('query', $operation, $columns);
    }

    private static function post_insert($normalized, $sql) {
        $pattern = '/^INSERT\\s+INTO\\s+`?([A-Za-z0-9_]*posts)`?\\s*\\((.+)\\)\\s+VALUES\\s*\\((.+)\\)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $columns = self::split_list($matches[2]);
        $values = self::split_list($matches[3]);
        if (count($columns) !== count($values)) {
            throw new CompatibilityException('HIB-WP-SQL-001', 'wp_posts INSERT column/value count mismatch.', 'wordpress.sql.translation', $sql);
        }

        $record = array();
        foreach ($columns as $index => $column) {
            $field = self::post_field($column, $sql);
            if ('id' === $field) {
                throw new CompatibilityException(
                    'HIB-WP-SQL-001',
                    'Explicit WordPress post IDs are not supported by the Kintone-backed proof.',
                    'wordpress.sql.translation',
                    $sql
                );
            }
            $record[$field] = self::sql_value($values[$index], $sql);
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'insert',
                'model' => 'Post',
                'record' => $record,
            )
        );
    }

    private static function post_update_by_id($normalized, $sql) {
        $pattern = '/^UPDATE\\s+`?([A-Za-z0-9_]*posts)`?\\s+SET\\s+(.+)\\s+WHERE\\s+`?ID`?\\s*=\\s*([^\\s]+)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $changes = array();
        foreach (self::split_list($matches[2]) as $assignment) {
            if (!preg_match('/^`?([A-Za-z0-9_]+)`?\\s*=\\s*(.+)$/s', $assignment, $parts)) {
                throw new CompatibilityException('HIB-WP-SQL-001', 'Unsupported wp_posts UPDATE assignment.', 'wordpress.sql.translation', $sql);
            }
            $field = self::post_field($parts[1], $sql);
            if ('id' === $field) {
                throw new CompatibilityException('HIB-WP-SQL-001', 'Changing WordPress post IDs is not supported.', 'wordpress.sql.translation', $sql);
            }
            $changes[$field] = self::sql_value($parts[2], $sql);
        }

        $id = self::sql_value($matches[3], $sql);
        if (!is_int($id) && !is_float($id)) {
            throw new CompatibilityException('HIB-WP-SQL-001', 'wp_posts UPDATE requires a numeric ID.', 'wordpress.sql.translation', $sql);
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'update',
                'model' => 'Post',
                'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $id),
                'changes' => $changes,
            )
        );
    }

    /**
     * Translate only stock WordPress SQL shapes explicitly proven by tests.
     * WordPress schema knowledge belongs here, not in @hibari/core or a backend.
     */
    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');

        foreach (array(
            'option_select',
            'option_select_names',
            'option_update',
            'option_delete',
            'option_insert_upsert',
            'post_select_by_id',
            'post_insert',
            'post_update_by_id',
        ) as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }

        throw new CompatibilityException(
            'HIB-WP-SQL-001',
            'The WordPress SQL statement is portable in shape but is not yet mapped to Hibari IR.',
            'wordpress.sql.translation',
            $sql
        );
    }
}