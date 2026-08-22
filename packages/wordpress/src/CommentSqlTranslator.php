<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 wp_comments SQL -> backend-neutral Comment IR.
 *
 * This intentionally covers only WP_Comment identity lookup plus the exact
 * wp_insert_comment()/wp_update_comment() row writes used by the focused proof.
 */
final class CommentSqlTranslator {
    private static $fields = array(
        'comment_id' => 'id',
        'comment_post_id' => 'postId',
        'comment_author' => 'author',
        'comment_author_email' => 'authorEmail',
        'comment_author_url' => 'authorUrl',
        'comment_author_ip' => 'authorIp',
        'comment_date' => 'date',
        'comment_date_gmt' => 'dateGmt',
        'comment_content' => 'content',
        'comment_karma' => 'karma',
        'comment_approved' => 'approved',
        'comment_agent' => 'agent',
        'comment_type' => 'type',
        'comment_parent' => 'parentId',
        'user_id' => 'userId',
    );

    private static $columns = array(
        'id' => 'comment_ID',
        'postId' => 'comment_post_ID',
        'author' => 'comment_author',
        'authorEmail' => 'comment_author_email',
        'authorUrl' => 'comment_author_url',
        'authorIp' => 'comment_author_IP',
        'date' => 'comment_date',
        'dateGmt' => 'comment_date_gmt',
        'content' => 'comment_content',
        'karma' => 'comment_karma',
        'approved' => 'comment_approved',
        'agent' => 'comment_agent',
        'type' => 'comment_type',
        'parentId' => 'comment_parent',
        'userId' => 'user_id',
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
            'HIB-WP-COMMENT-001',
            'Unsupported wp_comments literal in the proven WordPress subset.',
            'wordpress.comments',
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
                'HIB-WP-COMMENT-001',
                'Unsupported wp_comments column in the proven WordPress subset.',
                'wordpress.comments',
                $sql
            );
        }
        return self::$fields[$column];
    }

    private static function lookup($normalized, $sql) {
        if (!preg_match(
            '/^SELECT\\s+\\*\\s+FROM\\s+`?([A-Za-z0-9_]*comments)`?\\s+WHERE\\s+`?comment_ID`?\\s*=\\s*(\\d+)\\s+LIMIT\\s+1$/i',
            $normalized,
            $matches
        )) {
            return null;
        }
        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'Comment',
                'projection' => array_values(self::$fields),
                'filter' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[2]),
                'limit' => 1,
            ),
            self::$columns
        );
    }

    private static function insert($normalized, $sql) {
        if (!preg_match(
            '/^INSERT\\s+INTO\\s+`?([A-Za-z0-9_]*comments)`?\\s*\\(([^)]*)\\)\\s+VALUES\\s*\\((.*)\\)$/is',
            $normalized,
            $matches
        )) {
            return null;
        }
        $columns = self::split_list($matches[2]);
        $values = self::split_list($matches[3]);
        if (count($columns) !== count($values) || empty($columns)) {
            throw new CompatibilityException('HIB-WP-COMMENT-001', 'Malformed wp_comments INSERT.', 'wordpress.comments', $sql);
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
                'model' => 'Comment',
                'record' => $record,
            )
        );
    }

    private static function update($normalized, $sql) {
        if (!preg_match(
            '/^UPDATE\\s+`?([A-Za-z0-9_]*comments)`?\\s+SET\\s+(.+)\\s+WHERE\\s+`?comment_ID`?\\s*=\\s*(\\d+)$/is',
            $normalized,
            $matches
        )) {
            return null;
        }
        $changes = array();
        foreach (self::split_list($matches[2]) as $assignment) {
            if (!preg_match("/^`?([A-Za-z0-9_]+)`?\\s*=\\s*(.+)$/s", $assignment, $parts)) {
                throw new CompatibilityException('HIB-WP-COMMENT-001', 'Malformed wp_comments UPDATE assignment.', 'wordpress.comments', $sql);
            }
            $changes[self::field($parts[1], $sql)] = self::sql_value($parts[2], $sql);
        }
        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'update',
                'model' => 'Comment',
                'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[3]),
                'changes' => $changes,
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');
        foreach (array('lookup', 'insert', 'update') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }
}
