<?php

namespace Hibari\WordPress;

/**
 * Exact stock WordPress 7.1 autoload option preload -> Hibari QueryIR.
 *
 * This is intentionally separate from generic SQL translation. It admits only
 * the wp_load_alloptions() shape needed by stock Core and keeps an unbounded
 * fallback SELECT outside the proven subset.
 */
final class OptionPreloadSqlTranslator {
    private static function sql_string($value) {
        return str_replace(array("\\'", "\\\\"), array("'", "\\"), $value);
    }

    public static function translate($sql) {
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim((string) $sql)), ';');
        $pattern = "/^SELECT\\s+`?option_name`?\\s*,\\s*`?option_value`?\\s+FROM\\s+`?([A-Za-z0-9_]*options)`?\\s+WHERE\\s+`?autoload`?\\s+IN\\s*\\((.*)\\)$/i";
        if (!preg_match($pattern, $normalized, $matches)) {
            return null;
        }

        preg_match_all("/'((?:\\\\.|[^'])*)'/", $matches[2], $value_matches);
        $values = array_values(array_map(array(__CLASS__, 'sql_string'), $value_matches[1]));
        if (empty($values)) {
            throw new CompatibilityException(
                'HIB-WP-SQL-001',
                'WordPress autoload preload requires explicit autoload values.',
                'wordpress.options.preload',
                $sql
            );
        }

        return new SqlTranslation(
            'query',
            array(
                'kind' => 'query',
                'model' => 'Option',
                'projection' => array('name', 'value'),
                'filter' => array('op' => 'in', 'field' => 'autoload', 'values' => $values),
            ),
            array(
                'name' => 'option_name',
                'value' => 'option_value',
            )
        );
    }
}
