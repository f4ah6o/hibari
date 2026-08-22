<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 wp_term_relationships SQL -> Hibari IR adapter.
 *
 * Term/entity lookup is handled at the higher `terms_pre_query` semantic seam.
 * This class only owns the simple relationship SQL that WordPress still emits
 * for pair existence, attach, and detach.
 */
final class TaxonomySqlTranslator {
    private static function and_filter($filters) {
        return 1 === count($filters)
            ? $filters[0]
            : array('op' => 'and', 'expressions' => array_values($filters));
    }

    private static function numeric_list($text, $sql) {
        $ids = array();
        foreach (explode(',', $text) as $token) {
            $token = trim($token);
            if (preg_match("/^'(\\d+)'$/", $token, $matches)) {
                $token = $matches[1];
            }
            if (!preg_match('/^\d+$/', $token)) {
                throw new CompatibilityException(
                    'HIB-WP-TAXONOMY-001',
                    'Taxonomy relationship IDs must be numeric in the proven subset.',
                    'wordpress.relationEdges',
                    $sql
                );
            }
            $ids[] = (int) $token;
        }
        return $ids;
    }

    private static function pair_select($normalized, $sql) {
        $pattern = '/^SELECT\s+`?term_taxonomy_id`?\s+FROM\s+`?([A-Za-z0-9_]*term_relationships)`?\s+WHERE\s+`?object_id`?\s*=\s*(\d+)\s+AND\s+`?term_taxonomy_id`?\s*=\s*(\d+)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'TermRelationship',
                'projection' => array('rightId'),
                'filter' => self::and_filter(array(
                    array('op' => 'eq', 'field' => 'leftId', 'value' => (int) $matches[2]),
                    array('op' => 'eq', 'field' => 'rightId', 'value' => (int) $matches[3]),
                )),
                'limit' => 1,
            ),
            array('rightId' => 'term_taxonomy_id')
        );
    }

    private static function insert($normalized, $sql) {
        $pattern = '/^INSERT\s+INTO\s+`?([A-Za-z0-9_]*term_relationships)`?\s*\(\s*`?object_id`?\s*,\s*`?term_taxonomy_id`?(?:\s*,\s*`?term_order`?)?\s*\)\s+VALUES\s*\(\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(\d+))?\s*\)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $record = array(
            'leftId' => (int) $matches[2],
            'rightId' => (int) $matches[3],
        );
        if (isset($matches[4]) && '' !== $matches[4]) {
            $record['order'] = (int) $matches[4];
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'insert',
                'model' => 'TermRelationship',
                'record' => $record,
            )
        );
    }

    private static function delete($normalized, $sql) {
        $pattern = '/^DELETE\s+FROM\s+`?([A-Za-z0-9_]*term_relationships)`?\s+WHERE\s+`?object_id`?\s*=\s*(\d+)\s+AND\s+`?term_taxonomy_id`?\s+IN\s*\(([^)]*)\)$/i';
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        $right_ids = self::numeric_list($matches[3], $sql);
        if (empty($right_ids)) {
            throw new CompatibilityException(
                'HIB-WP-TAXONOMY-001',
                'Taxonomy relationship DELETE requires at least one term taxonomy ID.',
                'wordpress.relationEdges',
                $sql
            );
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'delete',
                'model' => 'TermRelationship',
                'where' => self::and_filter(array(
                    array('op' => 'eq', 'field' => 'leftId', 'value' => (int) $matches[2]),
                    array('op' => 'in', 'field' => 'rightId', 'values' => $right_ids),
                )),
            )
        );
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');

        foreach (array('pair_select', 'insert', 'delete') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }
}
