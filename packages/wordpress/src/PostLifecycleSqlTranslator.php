<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 post-lifecycle SQL -> backend-neutral Post IR.
 *
 * Basic identity CRUD remains in WordPressSqlTranslator. This class only owns
 * the compound parent/type selectors and final delete shape emitted by
 * wp_delete_post().
 */
final class PostLifecycleSqlTranslator {
    private static $fields = array(
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
        return strtr(
            $value,
            array(
                "\\0" => "\0",
                "\\\"" => '"',
                "\\'" => "'",
                "\\\\" => "\\",
            )
        );
    }

    private static function sql_value($token, $sql) {
        $token = trim($token);
        if (preg_match("/^'((?:\\\\.|[^'])*)'$/s", $token, $matches)) {
            return self::sql_string($matches[1]);
        }
        if (is_numeric($token)) {
            return false !== strpos($token, '.') ? (float) $token : (int) $token;
        }
        throw new CompatibilityException(
            'HIB-WP-POST-DELETE-001',
            'Unsupported wp_posts lifecycle literal in the proven WordPress subset.',
            'wordpress.posts.lifecycle',
            $sql
        );
    }

    private static function columns() {
        $columns = array();
        foreach (self::$fields as $column => $field) {
            $columns[$field] = 'id' === $column ? 'ID' : $column;
        }
        return $columns;
    }

    private static function parent_type_filter($parent_id, $post_type) {
        return array(
            'op' => 'and',
            'expressions' => array(
                array('op' => 'eq', 'field' => 'parentId', 'value' => (int) $parent_id),
                array('op' => 'eq', 'field' => 'type', 'value' => $post_type),
            ),
        );
    }

    private static function related_select($normalized, $sql) {
        if (!preg_match(
            "/^SELECT\\s+(\\*|`?ID`?)\\s+FROM\\s+`?([A-Za-z0-9_]*posts)`?\\s+WHERE\\s+`?post_parent`?\\s*=\\s*(\\d+)\\s+AND\\s+`?post_type`?\\s*=\\s*('(?:\\\\.|[^'])*')$/i",
            $normalized,
            $matches
        )) {
            return null;
        }

        $post_type = self::sql_value($matches[4], $sql);
        if (!is_string($post_type)) {
            throw new CompatibilityException(
                'HIB-WP-POST-DELETE-001',
                'wp_posts lifecycle post_type must be a string.',
                'wordpress.posts.lifecycle',
                $sql
            );
        }

        $operation = array(
            'kind' => 'query',
            'model' => 'Post',
            'filter' => self::parent_type_filter((int) $matches[3], $post_type),
        );

        if ('*' === $matches[1]) {
            return new SqlTranslation('query', $operation, self::columns());
        }

        $operation['projection'] = array('id');
        return new SqlTranslation('query', $operation, array('id' => 'ID'));
    }

    private static function parent_update($normalized, $sql) {
        if (!preg_match(
            "/^UPDATE\\s+`?([A-Za-z0-9_]*posts)`?\\s+SET\\s+`?post_parent`?\\s*=\\s*(\\d+)\\s+WHERE\\s+`?post_parent`?\\s*=\\s*(\\d+)\\s+AND\\s+`?post_type`?\\s*=\\s*('(?:\\\\.|[^'])*')$/i",
            $normalized,
            $matches
        )) {
            return null;
        }

        $post_type = self::sql_value($matches[4], $sql);
        if (!is_string($post_type)) {
            throw new CompatibilityException(
                'HIB-WP-POST-DELETE-001',
                'wp_posts lifecycle post_type must be a string.',
                'wordpress.posts.lifecycle',
                $sql
            );
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'updateMany',
                'model' => 'Post',
                'where' => self::parent_type_filter((int) $matches[3], $post_type),
                'changes' => array('parentId' => (int) $matches[2]),
            )
        );
    }

    private static function delete_by_id($normalized) {
        if (!preg_match(
            '/^DELETE\\s+FROM\\s+`?([A-Za-z0-9_]*posts)`?\\s+WHERE\\s+`?ID`?\\s*=\\s*(\\d+)$/i',
            $normalized,
            $matches
        )) {
            return null;
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'delete',
                'model' => 'Post',
                'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[2]),
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');
        foreach (array('related_select', 'parent_update', 'delete_by_id') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }
}
