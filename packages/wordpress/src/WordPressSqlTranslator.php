<?php

namespace Hibari\WordPress;

final class SqlTranslation {
    /** @var string */
    public $endpoint;

    /** @var array<string, mixed> */
    public $operation;

    /** @var array<string, string> application field => SQL output column */
    public $columns;

    public function __construct($endpoint, $operation, $columns = array()) {
        $this->endpoint = $endpoint;
        $this->operation = $operation;
        $this->columns = $columns;
    }
}

final class WordPressSqlTranslator {
    private static function sql_string($value) {
        return str_replace(array("\\'", "\\\\"), array("'", "\\"), $value);
    }

    /**
     * Translate the smallest stock WordPress SQL surface proven so far into
     * backend-neutral Hibari IR. WordPress schema knowledge belongs here, not
     * in @hibari/core or a concrete backend.
     *
     * @param string $sql
     * @return SqlTranslation
     */
    public static function translate($sql) {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $sql));

        $options_pattern = "/^SELECT\\s+`?option_value`?\\s+FROM\\s+`?([A-Za-z0-9_]*options)`?\\s+WHERE\\s+`?option_name`?\\s*=\\s*'((?:\\\\.|[^'])*)'\\s+LIMIT\\s+1$/i";
        if (preg_match($options_pattern, $normalized, $matches)) {
            $name = self::sql_string($matches[2]);
            return new SqlTranslation(
                'query',
                array(
                    'kind' => 'query',
                    'model' => 'Option',
                    'projection' => array('value'),
                    'filter' => array(
                        'op' => 'eq',
                        'field' => 'name',
                        'value' => $name,
                    ),
                    'limit' => 1,
                ),
                array('value' => 'option_value')
            );
        }

        throw new CompatibilityException(
            'HIB-WP-SQL-001',
            'The WordPress SQL statement is portable in shape but is not yet mapped to Hibari IR.',
            'wordpress.sql.translation',
            $sql
        );
    }
}
