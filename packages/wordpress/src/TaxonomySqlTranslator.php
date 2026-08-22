<?php

namespace Hibari\WordPress;

/**
 * Narrow stock WordPress 7.1 taxonomy SQL -> Hibari IR adapter.
 *
 * Most term/entity lookup is handled at the higher `terms_pre_query` semantic
 * seam. This class owns simple taxonomy row writes, relation edge SQL, and the
 * two exact JOIN reads that wp_insert_term() performs after a Term insert.
 * Those JOINs are executed as bounded Hibari IR composition, never as generic
 * JOIN execution in core.
 */
final class TaxonomySqlTranslator {
    private static $term_fields = array(
        'term_id' => 'id',
        'name' => 'name',
        'slug' => 'slug',
        'term_group' => 'group',
    );

    private static $term_taxonomy_fields = array(
        'term_taxonomy_id' => 'id',
        'term_id' => 'termId',
        'taxonomy' => 'taxonomy',
        'description' => 'description',
        'parent' => 'parent',
        'count' => 'count',
    );

    private static function and_filter($filters) {
        $filters = array_values($filters);
        return 1 === count($filters)
            ? $filters[0]
            : array('op' => 'and', 'expressions' => $filters);
    }

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
            'HIB-WP-TAXONOMY-001',
            'Unsupported taxonomy SQL literal in the proven WordPress subset.',
            'wordpress.taxonomy.translation',
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

    private static function mapped_insert($normalized, $sql) {
        if (!preg_match(
            '/^INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]*)\)\s+VALUES\s*\((.*)\)$/is',
            $normalized,
            $matches
        )) {
            return null;
        }

        $table = strtolower($matches[1]);
        $model = null;
        $field_map = null;
        if (preg_match('/(?:^|_)term_taxonomy$/', $table)) {
            $model = 'TermTaxonomy';
            $field_map = self::$term_taxonomy_fields;
        } elseif (preg_match('/(?:^|_)terms$/', $table)) {
            $model = 'Term';
            $field_map = self::$term_fields;
        } else {
            return null;
        }

        $columns = self::split_list($matches[2]);
        $values = self::split_list($matches[3]);
        if (count($columns) !== count($values)) {
            throw new CompatibilityException(
                'HIB-WP-TAXONOMY-001',
                'Taxonomy INSERT column/value arity does not match.',
                'wordpress.taxonomy.translation',
                $sql
            );
        }

        $record = array();
        foreach ($columns as $index => $column) {
            $column = strtolower(trim($column, " `\t\n\r\0\x0B"));
            if (!isset($field_map[$column])) {
                throw new CompatibilityException(
                    'HIB-WP-TAXONOMY-001',
                    'Unsupported taxonomy column in the proven WordPress INSERT subset.',
                    'wordpress.taxonomy.translation',
                    $sql
                );
            }
            $record[$field_map[$column]] = self::sql_value($values[$index], $sql);
        }

        return new SqlTranslation(
            'mutation',
            array(
                'kind' => 'mutation',
                'operation' => 'insert',
                'model' => $model,
                'record' => $record,
            )
        );
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

    private static function relation_insert($normalized, $sql) {
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

    private static function relation_delete($normalized, $sql) {
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

    public static function isSemanticJoin($sql) {
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');

        $existing_context = "/^SELECT\\s+tt\\.term_taxonomy_id\\s+FROM\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+ON\\s+tt\\.term_id\\s*=\\s*t\\.term_id\\s+WHERE\\s+tt\\.taxonomy\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+t\\.term_id\\s*=\\s*\\d+$/i";
        $duplicate_confidence = "/^SELECT\\s+t\\.term_id\\s*,\\s*t\\.slug\\s*,\\s*tt\\.term_taxonomy_id\\s*,\\s*tt\\.taxonomy\\s+FROM\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+ON\\s*\\(\\s*tt\\.term_id\\s*=\\s*t\\.term_id\\s*\\)\\s+WHERE\\s+t\\.slug\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+tt\\.parent\\s*=\\s*\\d+\\s+AND\\s+tt\\.taxonomy\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+t\\.term_id\\s*<\\s*\\d+\\s+AND\\s+tt\\.term_taxonomy_id\\s*!=\\s*\\d+$/i";

        return (bool) preg_match($existing_context, $normalized)
            || (bool) preg_match($duplicate_confidence, $normalized);
    }

    public static function executeSemantic($sql, $bridge) {
        if (!$bridge instanceof OperationBridge) {
            return null;
        }

        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');

        if (preg_match(
            "/^SELECT\\s+tt\\.term_taxonomy_id\\s+FROM\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+ON\\s+tt\\.term_id\\s*=\\s*t\\.term_id\\s+WHERE\\s+tt\\.taxonomy\\s*=\\s*'((?:\\\\.|[^'])*)'\\s+AND\\s+t\\.term_id\\s*=\\s*(\\d+)$/i",
            $normalized,
            $matches
        )) {
            $taxonomy = self::sql_string($matches[1]);
            $term_id = (int) $matches[2];
            $decoded = $bridge->executeOperation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'TermTaxonomy',
                    'projection' => array('id'),
                    'filter' => self::and_filter(array(
                        array('op' => 'eq', 'field' => 'termId', 'value' => $term_id),
                        array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                    )),
                    'limit' => 1,
                ),
                $sql
            );
            $rows = array();
            foreach (isset($decoded['records']) ? $decoded['records'] : array() as $record) {
                if (is_array($record) && isset($record['id'])) {
                    $rows[] = array('term_taxonomy_id' => (int) $record['id']);
                }
            }
            return new BridgeResult($rows, 0, null);
        }

        if (preg_match(
            "/^SELECT\\s+t\\.term_id\\s*,\\s*t\\.slug\\s*,\\s*tt\\.term_taxonomy_id\\s*,\\s*tt\\.taxonomy\\s+FROM\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+ON\\s*\\(\\s*tt\\.term_id\\s*=\\s*t\\.term_id\\s*\\)\\s+WHERE\\s+t\\.slug\\s*=\\s*'((?:\\\\.|[^'])*)'\\s+AND\\s+tt\\.parent\\s*=\\s*(\\d+)\\s+AND\\s+tt\\.taxonomy\\s*=\\s*'((?:\\\\.|[^'])*)'\\s+AND\\s+t\\.term_id\\s*<\\s*(\\d+)\\s+AND\\s+tt\\.term_taxonomy_id\\s*!=\\s*(\\d+)$/i",
            $normalized,
            $matches
        )) {
            $slug = self::sql_string($matches[1]);
            $parent = (int) $matches[2];
            $taxonomy = self::sql_string($matches[3]);
            $new_term_id = (int) $matches[4];

            $term_response = $bridge->executeOperation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'Term',
                    'projection' => array('id', 'slug'),
                    'filter' => self::and_filter(array(
                        array('op' => 'eq', 'field' => 'slug', 'value' => $slug),
                        array('op' => 'lt', 'field' => 'id', 'value' => $new_term_id),
                    )),
                    'ordering' => array(array('field' => 'id', 'direction' => 'asc')),
                ),
                $sql
            );

            $terms = isset($term_response['records']) && is_array($term_response['records'])
                ? $term_response['records']
                : array();
            $term_ids = array();
            $term_by_id = array();
            foreach ($terms as $term) {
                if (is_array($term) && isset($term['id'])) {
                    $id = (int) $term['id'];
                    $term_ids[] = $id;
                    $term_by_id[$id] = $term;
                }
            }
            if (empty($term_ids)) {
                return new BridgeResult();
            }

            $context_response = $bridge->executeOperation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'TermTaxonomy',
                    'projection' => array('id', 'termId', 'taxonomy'),
                    'filter' => self::and_filter(array(
                        array('op' => 'in', 'field' => 'termId', 'values' => array_values($term_ids)),
                        array('op' => 'eq', 'field' => 'parent', 'value' => $parent),
                        array('op' => 'eq', 'field' => 'taxonomy', 'value' => $taxonomy),
                    )),
                    'limit' => 1,
                ),
                $sql
            );

            $rows = array();
            foreach (isset($context_response['records']) ? $context_response['records'] : array() as $context) {
                if (!is_array($context) || !isset($context['id']) || !isset($context['termId'])) {
                    continue;
                }
                $term_id = (int) $context['termId'];
                if (!isset($term_by_id[$term_id])) {
                    continue;
                }
                $rows[] = array(
                    'term_id' => $term_id,
                    'slug' => isset($term_by_id[$term_id]['slug']) ? $term_by_id[$term_id]['slug'] : '',
                    'term_taxonomy_id' => (int) $context['id'],
                    'taxonomy' => isset($context['taxonomy']) ? $context['taxonomy'] : $taxonomy,
                );
            }
            return new BridgeResult($rows, 0, null);
        }

        return null;
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');

        foreach (array('pair_select', 'relation_insert', 'relation_delete', 'mapped_insert') as $translator) {
            $result = call_user_func(array(__CLASS__, $translator), $normalized, $sql);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }
}
