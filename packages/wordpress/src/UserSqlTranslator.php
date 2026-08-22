<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 wp_users SQL -> backend-neutral User IR.
 *
 * This is not WP_User_Query or generic SQL support. It accepts only identity,
 * login/email/slug lookup, nicename collision, and wp_insert_user() insert/update
 * shapes used by the focused user proof.
 */
final class UserSqlTranslator {
    private static $fields = array(
        'id' => 'id',
        'user_login' => 'login',
        'user_pass' => 'passwordHash',
        'user_nicename' => 'nicename',
        'user_email' => 'email',
        'user_url' => 'url',
        'user_registered' => 'registeredAt',
        'user_activation_key' => 'activationKey',
        'user_status' => 'status',
        'display_name' => 'displayName',
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
            'HIB-WP-USER-001',
            'Unsupported wp_users literal in the proven WordPress subset.',
            'wordpress.users',
            $sql
        );
    }

    private static function split_list($text) {
        $items = array();
        $current = '';
        $quoted = false;
        $escaped = false;
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
            if (',' === $char && !$quoted) {
                $items[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ('' !== trim($current)) {
            $items[] = trim($current);
        }
        return $items;
    }

    private static function field($column, $sql) {
        $column = strtolower(trim($column, " `\t\n\r\0\x0B"));
        if (!isset(self::$fields[$column])) {
            throw new CompatibilityException(
                'HIB-WP-USER-001',
                'Unsupported wp_users column in the proven WordPress subset.',
                'wordpress.users',
                $sql
            );
        }
        return self::$fields[$column];
    }

    private static function all_projection() {
        return array_values(self::$fields);
    }

    private static function all_columns() {
        $columns = array();
        foreach (self::$fields as $column => $field) {
            $columns[$field] = 'id' === $column ? 'ID' : $column;
        }
        return $columns;
    }

    private static function lookup($normalized, $sql) {
        if (!preg_match(
            "/^SELECT\\s+\\*\\s+FROM\\s+`?([A-Za-z0-9_]*users)`?\\s+WHERE\\s+`?(ID|user_login|user_email|user_nicename)`?\\s*=\\s*('(?:\\\\.|[^'])*'|\\d+)\\s+LIMIT\\s+1$/i",
            $normalized,
            $matches
        )) {
            return null;
        }
        $field = self::field($matches[2], $sql);
        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'User',
                'projection' => self::all_projection(),
                'filter' => array('op' => 'eq', 'field' => $field, 'value' => self::sql_value($matches[3], $sql)),
                'limit' => 1,
            ),
            self::all_columns()
        );
    }

    private static function nicename_collision($normalized, $sql) {
        if (!preg_match(
            "/^SELECT\\s+`?ID`?\\s+FROM\\s+`?([A-Za-z0-9_]*users)`?\\s+WHERE\\s+`?user_nicename`?\\s*=\\s*('(?:\\\\.|[^'])*')\\s+AND\\s+`?user_login`?\\s*!=\\s*('(?:\\\\.|[^'])*')\\s+LIMIT\\s+1$/i",
            $normalized,
            $matches
        )) {
            return null;
        }
        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'User',
                'projection' => array('id'),
                'filter' => array(
                    'op' => 'and',
                    'expressions' => array(
                        array('op' => 'eq', 'field' => 'nicename', 'value' => self::sql_value($matches[2], $sql)),
                        array('op' => 'ne', 'field' => 'login', 'value' => self::sql_value($matches[3], $sql)),
                    ),
                ),
                'limit' => 1,
            ),
            array('id' => 'ID')
        );
    }

    private static function insert($normalized, $sql) {
        if (!preg_match(
            '/^INSERT\\s+INTO\\s+`?([A-Za-z0-9_]*users)`?\\s*\\(([^)]*)\\)\\s+VALUES\\s*\\((.*)\\)$/is',
            $normalized,
            $matches
        )) {
            return null;
        }
        $columns = self::split_list($matches[2]);
        $values = self::split_list($matches[3]);
        if (count($columns) !== count($values) || empty($columns)) {
            throw new CompatibilityException('HIB-WP-USER-001', 'Malformed wp_users INSERT.', 'wordpress.users', $sql);
        }
        $record = array();
        foreach ($columns as $index => $column) {
            $record[self::field($column, $sql)] = self::sql_value($values[$index], $sql);
        }
        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'insert',
                'model' => 'User',
                'record' => $record,
            )
        );
    }

    private static function update($normalized, $sql) {
        if (!preg_match(
            '/^UPDATE\\s+`?([A-Za-z0-9_]*users)`?\\s+SET\\s+(.+)\\s+WHERE\\s+`?ID`?\\s*=\\s*(\\d+)$/is',
            $normalized,
            $matches
        )) {
            return null;
        }
        $changes = array();
        foreach (self::split_list($matches[2]) as $assignment) {
            if (!preg_match("/^`?([A-Za-z0-9_]+)`?\\s*=\\s*(.+)$/s", $assignment, $parts)) {
                throw new CompatibilityException('HIB-WP-USER-001', 'Malformed wp_users UPDATE assignment.', 'wordpress.users', $sql);
            }
            $changes[self::field($parts[1], $sql)] = self::sql_value($parts[2], $sql);
        }
        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'update',
                'model' => 'User',
                'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[3]),
                'changes' => $changes,
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');
        foreach (array('lookup', 'nicename_collision', 'insert', 'update') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }
}
