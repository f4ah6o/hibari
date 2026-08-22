<?php

namespace Hibari\WordPress;

/**
 * High-level projection for the small stock taxonomy subset proven by Hibari.
 *
 * WP_Term_Query exposes `terms_pre_query` before executing its generated JOIN
 * SQL. Hibari uses that semantic seam for bounded term-context and relation-edge
 * reads instead of teaching the generic core how to execute relational JOINs.
 */
final class TaxonomyProjection {
    /** @var OperationBridge|null */
    private static $bridge;

    public static function register($bridge) {
        if (!$bridge instanceof OperationBridge) {
            return;
        }
        self::$bridge = $bridge;
        add_filter('terms_pre_query', array(__CLASS__, 'pre_query'), 10, 2);
    }

    private static function query($operation, $context) {
        if (!self::$bridge instanceof OperationBridge) {
            return array();
        }
        $result = self::$bridge->executeOperation('query', $operation, $context);
        return isset($result['records']) && is_array($result['records'])
            ? $result['records']
            : array();
    }

    private static function taxonomies($args) {
        $taxonomies = isset($args['taxonomy']) ? $args['taxonomy'] : array();
        if (!is_array($taxonomies)) {
            $taxonomies = array($taxonomies);
        }
        return array_values(array_filter(array_map('strval', $taxonomies), function ($value) {
            return '' !== $value;
        }));
    }

    private static function term_exists_projection($args, $taxonomy) {
        $include = isset($args['include']) && is_array($args['include'])
            ? array_values($args['include'])
            : array();
        if (
            'all' !== (isset($args['fields']) ? $args['fields'] : null)
            || 1 !== count($include)
            || !is_numeric($include[0])
            || !empty($args['object_ids'])
        ) {
            return null;
        }

        $term_id = (int) $include[0];
        $records = self::query(
            array(
                'kind' => 'query',
                'model' => 'TermTaxonomy',
                'projection' => array('id', 'termId'),
                'filter' => array(
                    'op' => 'and',
                    'expressions' => array(
                        array('op' => 'eq', 'field' => 'termId', 'value' => $term_id),
                        array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                    ),
                ),
                'limit' => 1,
            ),
            'wordpress.taxonomy.termExists'
        );

        if (empty($records) || !is_array($records[0])) {
            return array();
        }

        $record = $records[0];
        if (!isset($record['id']) || !isset($record['termId'])) {
            return array();
        }

        $term = new \stdClass();
        $term->term_id = (int) $record['termId'];
        $term->term_taxonomy_id = (int) $record['id'];
        $term->taxonomy = $taxonomy;
        return array($term);
    }

    private static function object_tt_ids_projection($args, $taxonomy) {
        if ('tt_ids' !== (isset($args['fields']) ? $args['fields'] : null)) {
            return null;
        }

        $object_ids = isset($args['object_ids']) && is_array($args['object_ids'])
            ? array_values(array_unique(array_map('intval', $args['object_ids'])))
            : array();
        $object_ids = array_values(array_filter($object_ids, function ($value) {
            return $value > 0;
        }));
        if (empty($object_ids)) {
            return null;
        }

        $edges = self::query(
            array(
                'kind' => 'query',
                'model' => 'TermRelationship',
                'projection' => array('rightId'),
                'filter' => array('op' => 'in', 'field' => 'leftId', 'values' => $object_ids),
            ),
            'wordpress.taxonomy.objectRelations'
        );

        $right_ids = array();
        foreach ($edges as $edge) {
            if (is_array($edge) && isset($edge['rightId']) && is_numeric($edge['rightId'])) {
                $right_ids[] = (int) $edge['rightId'];
            }
        }
        $right_ids = array_values(array_unique($right_ids));
        if (empty($right_ids)) {
            return array();
        }

        // The physical relationship row only carries the term-taxonomy identity.
        // Resolve taxonomy context with one bounded second query rather than a JOIN.
        $contexts = self::query(
            array(
                'kind' => 'query',
                'model' => 'TermTaxonomy',
                'projection' => array('id'),
                'filter' => array(
                    'op' => 'and',
                    'expressions' => array(
                        array('op' => 'in', 'field' => 'id', 'values' => $right_ids),
                        array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                    ),
                ),
            ),
            'wordpress.taxonomy.relationContext'
        );

        $allowed = array();
        foreach ($contexts as $context) {
            if (is_array($context) && isset($context['id']) && is_numeric($context['id'])) {
                $allowed[(int) $context['id']] = true;
            }
        }

        return array_values(array_filter($right_ids, function ($id) use ($allowed) {
            return isset($allowed[$id]);
        }));
    }

    /**
     * @param mixed         $terms
     * @param \WP_Term_Query $query
     * @return mixed
     */
    public static function pre_query($terms, $query) {
        if (null !== $terms || !is_object($query) || !isset($query->query_vars)) {
            return $terms;
        }

        $args = $query->query_vars;
        $taxonomies = self::taxonomies($args);
        if (1 !== count($taxonomies)) {
            return null;
        }
        $taxonomy = $taxonomies[0];

        $projected = self::object_tt_ids_projection($args, $taxonomy);
        if (null !== $projected) {
            return $projected;
        }

        $projected = self::term_exists_projection($args, $taxonomy);
        if (null !== $projected) {
            return $projected;
        }

        return null;
    }
}
