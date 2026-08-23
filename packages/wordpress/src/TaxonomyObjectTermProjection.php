<?php

namespace Hibari\WordPress;

/**
 * Narrow object-scoped term-ID projection used by stock WordPress lifecycle
 * code before WP_Term_Query would otherwise generate a relational JOIN.
 *
 * This remains a consumer-side semantic projection: it lowers only existing
 * TermRelationship and TermTaxonomy reads into backend-neutral Hibari IR.
 */
final class TaxonomyObjectTermProjection {
    /** @var OperationBridge|null */
    private static $bridge;

    public static function register($bridge) {
        if (!$bridge instanceof OperationBridge) {
            return;
        }
        self::$bridge = $bridge;
        add_filter('terms_pre_query', array(__CLASS__, 'pre_query'), 9, 2);
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

    private static function object_ids($args) {
        $object_ids = isset($args['object_ids']) && is_array($args['object_ids'])
            ? array_values(array_unique(array_map('intval', $args['object_ids'])))
            : array();
        return array_values(array_filter($object_ids, function ($value) {
            return $value > 0;
        }));
    }

    /**
     * @param mixed            $terms
     * @param \WP_Term_Query   $query
     * @return mixed
     */
    public static function pre_query($terms, $query) {
        if (null !== $terms || !is_object($query) || !isset($query->query_vars)) {
            return $terms;
        }

        $args = $query->query_vars;
        if ('ids' !== (isset($args['fields']) ? $args['fields'] : null)) {
            return null;
        }

        $taxonomies = self::taxonomies($args);
        if (1 !== count($taxonomies)) {
            return null;
        }
        $taxonomy = $taxonomies[0];

        $object_ids = self::object_ids($args);
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
            'wordpress.taxonomy.objectTermIdRelations'
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

        $contexts = self::query(
            array(
                'kind' => 'query',
                'model' => 'TermTaxonomy',
                'projection' => array('id', 'termId'),
                'filter' => array(
                    'op' => 'and',
                    'expressions' => array(
                        array('op' => 'in', 'field' => 'id', 'values' => $right_ids),
                        array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                    ),
                ),
            ),
            'wordpress.taxonomy.objectTermIdContexts'
        );

        $term_by_context = array();
        foreach ($contexts as $context) {
            if (
                is_array($context)
                && isset($context['id'], $context['termId'])
                && is_numeric($context['id'])
                && is_numeric($context['termId'])
            ) {
                $term_by_context[(int) $context['id']] = (int) $context['termId'];
            }
        }

        $term_ids = array();
        foreach ($right_ids as $right_id) {
            if (isset($term_by_context[$right_id])) {
                $term_ids[] = $term_by_context[$right_id];
            }
        }

        return array_values(array_unique($term_ids));
    }
}
