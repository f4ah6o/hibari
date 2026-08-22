<?php

namespace Hibari\WordPress;

/**
 * High-level projection for the small stock taxonomy subset proven by Hibari.
 *
 * WP_Term_Query exposes `terms_pre_query` before executing its generated JOIN
 * SQL. Hibari uses that semantic seam for bounded term/context and relation-edge
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

    private static function values($value) {
        if (is_array($value)) {
            return array_values($value);
        }
        if ('' === $value || null === $value) {
            return array();
        }
        return array($value);
    }

    private static function field_filter($field, $values) {
        $values = array_values($values);
        if (1 === count($values)) {
            return array('op' => 'eq', 'field' => $field, 'value' => $values[0]);
        }
        return array('op' => 'in', 'field' => $field, 'values' => $values);
    }

    private static function and_filter($filters) {
        $filters = array_values($filters);
        return 1 === count($filters)
            ? $filters[0]
            : array('op' => 'and', 'expressions' => $filters);
    }

    private static function term_selector($args) {
        $include = self::values(isset($args['include']) ? $args['include'] : array());
        if (!empty($include)) {
            return array('field' => 'id', 'values' => array_map('intval', $include));
        }

        $names = self::values(isset($args['name']) ? $args['name'] : '');
        if (!empty($names)) {
            return array('field' => 'name', 'values' => array_map('strval', $names));
        }

        $slugs = self::values(isset($args['slug']) ? $args['slug'] : '');
        if (!empty($slugs)) {
            return array('field' => 'slug', 'values' => array_map('strval', $slugs));
        }

        return null;
    }

    private static function query_terms($selector, $context) {
        if (null === $selector || empty($selector['values'])) {
            return array();
        }

        return self::query(
            array(
                'kind' => 'query',
                'model' => 'Term',
                'projection' => array('id', 'name', 'slug', 'group'),
                'filter' => self::field_filter($selector['field'], $selector['values']),
                'ordering' => array(array('field' => 'id', 'direction' => 'asc')),
            ),
            $context
        );
    }

    private static function query_contexts($term_ids, $taxonomy, $parent, $context) {
        if (empty($term_ids)) {
            return array();
        }

        $filters = array(
            self::field_filter('termId', array_values(array_unique(array_map('intval', $term_ids)))),
            array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
        );
        if ('' !== $parent && null !== $parent) {
            $filters[] = array('op' => 'eq', 'field' => 'parent', 'value' => (int) $parent);
        }

        return self::query(
            array(
                'kind' => 'query',
                'model' => 'TermTaxonomy',
                'projection' => array('id', 'termId', 'taxonomy', 'description', 'parent', 'count'),
                'filter' => self::and_filter($filters),
            ),
            $context
        );
    }

    private static function term_object($term, $context) {
        $raw = (object) array(
            'term_id' => (int) $term['id'],
            'name' => isset($term['name']) ? (string) $term['name'] : '',
            'slug' => isset($term['slug']) ? (string) $term['slug'] : '',
            'term_group' => isset($term['group']) ? (int) $term['group'] : 0,
            'term_taxonomy_id' => (int) $context['id'],
            'taxonomy' => isset($context['taxonomy']) ? (string) $context['taxonomy'] : '',
            'description' => isset($context['description']) ? (string) $context['description'] : '',
            'parent' => isset($context['parent']) ? (int) $context['parent'] : 0,
            'count' => isset($context['count']) ? (int) $context['count'] : 0,
            'filter' => 'raw',
        );

        return class_exists('WP_Term') ? new \WP_Term($raw) : $raw;
    }

    private static function format_terms($pairs, $fields) {
        if ('all' === $fields) {
            return array_map(function ($pair) {
                return self::term_object($pair['term'], $pair['context']);
            }, $pairs);
        }
        if ('ids' === $fields) {
            return array_values(array_map(function ($pair) {
                return (int) $pair['term']['id'];
            }, $pairs));
        }
        if ('tt_ids' === $fields) {
            return array_values(array_map(function ($pair) {
                return (int) $pair['context']['id'];
            }, $pairs));
        }
        if ('names' === $fields) {
            return array_values(array_map(function ($pair) {
                return (string) $pair['term']['name'];
            }, $pairs));
        }
        if ('slugs' === $fields) {
            return array_values(array_map(function ($pair) {
                return (string) $pair['term']['slug'];
            }, $pairs));
        }
        return null;
    }

    private static function global_term_projection($args) {
        $fields = isset($args['fields']) ? $args['fields'] : 'all';
        if (!in_array($fields, array('ids', 'names', 'slugs'), true) || !empty($args['object_ids'])) {
            return null;
        }

        $selector = self::term_selector($args);
        if (null === $selector) {
            return null;
        }

        $terms = self::query_terms($selector, 'wordpress.taxonomy.globalTermLookup');
        if ('ids' === $fields) {
            return array_values(array_map(function ($term) {
                return (int) $term['id'];
            }, $terms));
        }
        if ('names' === $fields) {
            return array_values(array_map(function ($term) {
                return (string) $term['name'];
            }, $terms));
        }
        return array_values(array_map(function ($term) {
            return (string) $term['slug'];
        }, $terms));
    }

    private static function term_entity_projection($args, $taxonomy) {
        if (!empty($args['object_ids'])) {
            return null;
        }

        $fields = isset($args['fields']) ? $args['fields'] : 'all';
        if (!in_array($fields, array('all', 'ids', 'tt_ids', 'names', 'slugs'), true)) {
            return null;
        }

        $selector = self::term_selector($args);
        $parent = isset($args['parent']) ? $args['parent'] : '';

        // Support selector-scoped queries plus the exact sibling lookup used by
        // wp_insert_term(), where an explicit parent (including 0) scopes the set.
        if (null === $selector && '' === $parent) {
            return null;
        }

        $terms = array();
        $contexts = array();

        if (null !== $selector) {
            $terms = self::query_terms($selector, 'wordpress.taxonomy.termCandidates');
            $term_ids = array_values(array_map(function ($term) {
                return (int) $term['id'];
            }, $terms));
            $contexts = self::query_contexts(
                $term_ids,
                $taxonomy,
                $parent,
                'wordpress.taxonomy.termCandidateContexts'
            );
        } else {
            $filters = array(
                array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                array('op' => 'eq', 'field' => 'parent', 'value' => (int) $parent),
            );
            $contexts = self::query(
                array(
                    'kind' => 'query',
                    'model' => 'TermTaxonomy',
                    'projection' => array('id', 'termId', 'taxonomy', 'description', 'parent', 'count'),
                    'filter' => self::and_filter($filters),
                ),
                'wordpress.taxonomy.siblingContexts'
            );
            $term_ids = array_values(array_unique(array_map(function ($context) {
                return (int) $context['termId'];
            }, $contexts)));
            if (!empty($term_ids)) {
                $terms = self::query_terms(
                    array('field' => 'id', 'values' => $term_ids),
                    'wordpress.taxonomy.siblingTerms'
                );
            }
        }

        $term_by_id = array();
        foreach ($terms as $term) {
            if (is_array($term) && isset($term['id'])) {
                $term_by_id[(int) $term['id']] = $term;
            }
        }

        $pairs = array();
        foreach ($contexts as $context) {
            if (!is_array($context) || !isset($context['termId'])) {
                continue;
            }
            $term_id = (int) $context['termId'];
            if (isset($term_by_id[$term_id])) {
                $pairs[] = array('term' => $term_by_id[$term_id], 'context' => $context);
            }
        }

        usort($pairs, function ($left, $right) use ($args) {
            $orderby = isset($args['orderby']) ? strtolower((string) $args['orderby']) : 'name';
            if ('term_id' === $orderby || 'id' === $orderby || 'none' === $orderby) {
                return (int) $left['term']['id'] <=> (int) $right['term']['id'];
            }
            return strcmp((string) $left['term']['name'], (string) $right['term']['name']);
        });

        $number = isset($args['number']) ? (int) $args['number'] : 0;
        if ($number > 0) {
            $pairs = array_slice($pairs, 0, $number);
        }

        return self::format_terms($pairs, $fields);
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
     * @param mixed            $terms
     * @param \WP_Term_Query $query
     * @return mixed
     */
    public static function pre_query($terms, $query) {
        if (null !== $terms || !is_object($query) || !isset($query->query_vars)) {
            return $terms;
        }

        $args = $query->query_vars;
        $taxonomies = self::taxonomies($args);

        if (0 === count($taxonomies)) {
            return self::global_term_projection($args);
        }
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

        return self::term_entity_projection($args, $taxonomy);
    }
}
