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
            'Unsupported SQL literal in WordPress option statement.',
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
        $column = trim($column, " `\t\n\r\0\x0B");
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
            $field = self::option_field(strtolower($parts[1]), $sql);
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
            $field = self::option_field(strtolower(trim($column, " `\t\n\r\0\x0B")), $sql);
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

    /**
     * Translate only stock WordPress SQL shapes explicitly proven by tests.
     * WordPress schema knowledge belongs here, not in @hibari/core or a backend.
     */
    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');

        foreach (array('option_select', 'option_update', 'option_delete', 'option_insert_upsert') as $translator) {
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
