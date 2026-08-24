<?php

namespace Hibari\WordPress;

/**
 * Deterministic compatibility report over explicit SQL cases.
 *
 * Source/plugin discovery belongs to a later tooling layer. This contract only
 * converts the existing WordPress consumer preflight result into a stable,
 * machine-readable shape and never crosses the runtime/backend bridge.
 */
final class CompatibilityReport {
    const VERSION = 1;
    const PROFILE = 'wordpress-portable-v0';

    private static function operation($sql) {
        if (preg_match('/^\s*([A-Za-z]+)/', (string) $sql, $matches)) {
            return strtolower($matches[1]);
        }
        return 'unknown';
    }

    private static function unsupported_item($id, $sql, $exception) {
        return array(
            'id' => (string) $id,
            'classification' => 'unsupported',
            'operation' => self::operation($sql),
            'diagnostics' => array(
                array(
                    'code' => $exception->diagnostic_code,
                    'severity' => 'error',
                    'capability' => $exception->capability,
                    'message' => $exception->getMessage(),
                ),
            ),
        );
    }

    /**
     * @param array<int, array{id:mixed, sql:mixed}> $cases
     * @return array<string, mixed>
     */
    public static function inspect($cases) {
        $items = array();
        $portable = 0;
        $unsupported = 0;

        foreach ((array) $cases as $index => $case) {
            if (!is_array($case) || !array_key_exists('id', $case) || !array_key_exists('sql', $case)) {
                throw new \InvalidArgumentException(
                    'Compatibility report cases must contain id and sql at index ' . (string) $index . '.'
                );
            }

            $id = (string) $case['id'];
            $sql = (string) $case['sql'];

            try {
                $plan = SqlPreflight::inspect($sql);
                $items[] = array(
                    'id' => $id,
                    'classification' => $plan->classification,
                    'operation' => $plan->operation,
                    'diagnostics' => array(),
                );
                ++$portable;
            } catch (CompatibilityException $exception) {
                $items[] = self::unsupported_item($id, $sql, $exception);
                ++$unsupported;
            }
        }

        return array(
            'version' => self::VERSION,
            'profile' => self::PROFILE,
            'compatible' => 0 === $unsupported,
            'summary' => array(
                'total' => count($items),
                'portable' => $portable,
                'unsupported' => $unsupported,
            ),
            'items' => $items,
        );
    }
}
