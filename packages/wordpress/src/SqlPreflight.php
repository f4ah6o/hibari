<?php

namespace Hibari\WordPress;

final class SqlPreflight {
    private static function unsupported($code, $message, $capability, $sql) {
        throw new CompatibilityException($code, $message, $capability, $sql);
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

        if (preg_match('/\bJOIN\b/i', $normalized)) {
            self::unsupported(
                'HIB-WP-JOIN-001',
                'JOIN semantics are not part of the initial portable WordPress SQL subset.',
                'query.join',
                $sql
            );
        }

        if (preg_match('/\bGROUP\s+BY\b/i', $normalized) || preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $normalized)) {
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
