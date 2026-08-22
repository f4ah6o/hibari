<?php

namespace Hibari\WordPress;

/**
 * Exact projection for WordPress' hierarchical taxonomy cache regeneration.
 *
 * clean_term_cache() calls _get_term_hierarchy(), whose WP_Term_Query requests
 * `fields=id=>parent` for one known taxonomy. The result is already a semantic
 * term-id => parent-id map, so resolve it from TermTaxonomy records without
 * admitting the JOIN SQL WP_Term_Query would otherwise generate.
 */
final class TaxonomyHierarchyProjection {
    /** @var OperationBridge|null */
    private static $bridge;

    public static function register($bridge) {
        if (!$bridge instanceof OperationBridge) {
            return;
        }
        self::$bridge = $bridge;
        add_filter('terms_pre_query', array(__CLASS__, 'pre_query'), 9, 2);
    }

    public static function pre_query($terms, $query) {
        if (null !== $terms || !self::$bridge instanceof OperationBridge || !is_object($query) || !isset($query->query_vars)) {
            return $terms;
        }

        $args = $query->query_vars;
        $taxonomies = isset($args['taxonomy']) ? (array) $args['taxonomy'] : array();
        $taxonomies = array_values(array_filter(array_map('strval', $taxonomies), function ($value) {
            return '' !== $value;
        }));

        if (
            1 !== count($taxonomies)
            || 'id=>parent' !== (isset($args['fields']) ? $args['fields'] : null)
            || 'all' !== (isset($args['get']) ? $args['get'] : null)
            || !empty($args['object_ids'])
        ) {
            return null;
        }

        $taxonomy = $taxonomies[0];
        $decoded = self::$bridge->executeOperation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'TermTaxonomy',
                'projection' => array('termId', 'parent'),
                'filter' => array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                'ordering' => array(array('field' => 'termId', 'direction' => 'asc')),
            ),
            'wordpress.taxonomy.hierarchyCache'
        );

        $parents = array();
        foreach (isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : array() as $record) {
            if (!is_array($record) || !isset($record['termId'])) {
                continue;
            }
            $parents[(int) $record['termId']] = isset($record['parent']) ? (int) $record['parent'] : 0;
        }

        return $parents;
    }
}
