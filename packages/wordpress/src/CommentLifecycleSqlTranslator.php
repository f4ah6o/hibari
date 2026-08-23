<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 comment-lifecycle SQL -> backend-neutral Comment IR.
 *
 * Identity lookup/insert/update stay in CommentSqlTranslator. This class owns
 * only the scoped selectors and delete/update shapes used by wp_delete_comment().
 */
final class CommentLifecycleSqlTranslator {
    private static function scoped_id_select($normalized) {
        if (preg_match(
            '/^SELECT\\s+`?comment_ID`?\\s+FROM\\s+`?([A-Za-z0-9_]*comments)`?\\s+WHERE\\s+`?comment_post_ID`?\\s*=\\s*(\\d+)\\s+ORDER\\s+BY\\s+`?comment_ID`?\\s+DESC$/i',
            $normalized,
            $matches
        )) {
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'Comment',
                    'projection' => array('id'),
                    'filter' => array('op' => 'eq', 'field' => 'postId', 'value' => (int) $matches[2]),
                    'ordering' => array(array('field' => 'id', 'direction' => 'desc')),
                ),
                array('id' => 'comment_ID')
            );
        }

        if (preg_match(
            '/^SELECT\\s+`?comment_ID`?\\s+FROM\\s+`?([A-Za-z0-9_]*comments)`?\\s+WHERE\\s+`?comment_parent`?\\s*=\\s*(\\d+)$/i',
            $normalized,
            $matches
        )) {
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'Comment',
                    'projection' => array('id'),
                    'filter' => array('op' => 'eq', 'field' => 'parentId', 'value' => (int) $matches[2]),
                ),
                array('id' => 'comment_ID')
            );
        }

        return null;
    }

    private static function parent_update($normalized) {
        if (!preg_match(
            '/^UPDATE\\s+`?([A-Za-z0-9_]*comments)`?\\s+SET\\s+`?comment_parent`?\\s*=\\s*(\\d+)\\s+WHERE\\s+`?comment_parent`?\\s*=\\s*(\\d+)$/i',
            $normalized,
            $matches
        )) {
            return null;
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'updateMany',
                'model' => 'Comment',
                'where' => array('op' => 'eq', 'field' => 'parentId', 'value' => (int) $matches[3]),
                'changes' => array('parentId' => (int) $matches[2]),
            )
        );
    }

    private static function delete_by_id($normalized) {
        if (!preg_match(
            '/^DELETE\\s+FROM\\s+`?([A-Za-z0-9_]*comments)`?\\s+WHERE\\s+`?comment_ID`?\\s*=\\s*(\\d+)$/i',
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
                'model' => 'Comment',
                'where' => array('op' => 'eq', 'field' => 'id', 'value' => (int) $matches[2]),
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\\s+/', ' ', trim((string) $sql)), ';');
        foreach (array('scoped_id_select', 'parent_update', 'delete_by_id') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }
}
