<?php

namespace Hibari\WordPress;

final class SqlPreflight {
    private static function unsupported($code, $message, $capability, $sql) {
        throw new CompatibilityException($code, $message, $capability, $sql);
    }

    private static function is_dynamic_attribute_existence_count($normalized) {
        return (bool) preg_match(
            "/^SELECT\\s+COUNT\\(\\*\\)\\s+FROM\\s+`?[A-Za-z0-9_]*(postmeta|usermeta|commentmeta)`?\\s+WHERE\\s+`?meta_key`?\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+`?(post_id|user_id|comment_id)`?\\s*=\\s*\\d+$/i",
            $normalized,
            $matches
        ) && (
            ('postmeta' === strtolower($matches[1]) && 'post_id' === strtolower($matches[2]))
            || ('usermeta' === strtolower($matches[1]) && 'user_id' === strtolower($matches[2]))
            || ('commentmeta' === strtolower($matches[1]) && 'comment_id' === strtolower($matches[2]))
        );
    }

    private static function is_taxonomy_semantic_join($normalized) {
        $existing_context = "/^SELECT\\s+tt\\.term_taxonomy_id\\s+FROM\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+ON\\s+tt\\.term_id\\s*=\\s*t\\.term_id\\s+WHERE\\s+tt\\.taxonomy\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+t\\.term_id\\s*=\\s*\\d+$/i";
        $duplicate_confidence = "/^SELECT\\s+t\\.term_id\\s*,\\s*t\\.slug\\s*,\\s*tt\\.term_taxonomy_id\\s*,\\s*tt\\.taxonomy\\s+FROM\\s+`?[A-Za-z0-9_]*terms`?\\s+AS\\s+t\\s+INNER\\s+JOIN\\s+`?[A-Za-z0-9_]*term_taxonomy`?\\s+AS\\s+tt\\s+ON\\s*\\(\\s*tt\\.term_id\\s*=\\s*t\\.term_id\\s*\\)\\s+WHERE\\s+t\\.slug\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+tt\\.parent\\s*=\\s*\\d+\\s+AND\\s+tt\\.taxonomy\\s*=\\s*'(?:\\\\.|[^'])*'\\s+AND\\s+t\\.term_id\\s*<\\s*\\d+\\s+AND\\s+tt\\.term_taxonomy_id\\s*!=\\s*\\d+$/i";
        return (bool) preg_match($existing_context, $normalized)
            || (bool) preg_match($duplicate_confidence, $normalized);
    }

    /**
     * Consumer-level SQL preflight only. Backend-specific capability planning
     * happens after the bridge boundary.
     *
     * @param string $sql
     * @return SqlPlan
     */
    public static function inspect($sql) {
        $trimmed = trim((string) $sql);
        $normalized = preg_replace('/\s+/', ' ', $trimmed);

        if ('' === $trimmed) {
            self::unsupported(
                'HIB-WP-SQL-001',
                'Empty SQL cannot be translated by the Hibari WordPress adapter.',
                'wordpress.sql',
                $sql
            );
        }

        if (preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i', $normalized)) {
            self::unsupported(
                'HIB-WP-TXN-001',
                'Interactive transaction SQL is outside the portable Hibari WordPress profile.',
                'transaction.interactive',
                $sql
            );
        }

        if (preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $normalized)) {
            self::unsupported(
                'HIB-WP-DDL-001',
                'Schema and DDL SQL is not executable through the Hibari WordPress runtime profile.',
                'schema.migration',
                $sql
            );
        }

        if (preg_match('/\bJOIN\b/i', $normalized) && !self::is_taxonomy_semantic_join($normalized)) {
            self::unsupported(
                'HIB-WP-JOIN-001',
                'JOIN semantics are not part of the initial portable WordPress SQL subset.',
                'query.join',
                $sql
            );
        }

        $dynamic_attribute_existence_count = self::is_dynamic_attribute_existence_count($normalized);
        if (
            preg_match('/\bGROUP\s+BY\b/i', $normalized)
            || (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $normalized) && !$dynamic_attribute_existence_count)
        ) {
            self::unsupported(
                'HIB-WP-AGG-001',
                'Aggregate SQL is not part of the initial portable WordPress SQL subset.',
                'query.aggregate',
                $sql
            );
        }

        if (preg_match('/\(\s*SELECT\b/i', $normalized)) {
            self::unsupported(
                'HIB-WP-SUBQUERY-001',
                'Subqueries are not part of the initial portable WordPress SQL subset.',
                'query.subquery',
                $sql
            );
        }

        if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $normalized, $matches)) {
            self::unsupported(
                'HIB-WP-SQL-001',
                'The SQL statement shape is outside the initial Hibari WordPress subset.',
                'wordpress.sql',
                $sql
            );
        }

        return new SqlPlan('portable', strtolower($matches[1]));
    }
}
