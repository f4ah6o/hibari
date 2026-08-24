<?php

namespace Hibari\WordPress;

/**
 * Deterministic compatibility report over explicit SQL cases.
 *
 * Discovery belongs to a separate tooling layer. This contract converts the
 * existing WordPress consumer preflight result into a stable, machine-readable
 * shape and never crosses the runtime/backend bridge.
 */
final class CompatibilityReport {
    const VERSION = 1;
    const PROFILE = 'wordpress-portable-v0';

    private static $classifications = array('native', 'emulated', 'expensive', 'unsupported');

    private static function operation($sql) {
        if (preg_match('/^\s*([A-Za-z]+)/', (string) $sql, $matches)) {
            return strtolower($matches[1]);
        }
        return 'unknown';
    }

    private static function source($case) {
        if (!isset($case['source']) || !is_array($case['source']) || !isset($case['source']['file'])) {
            return null;
        }

        $source = array('file' => (string) $case['source']['file']);
        if (isset($case['source']['line'])) {
            $source['line'] = (int) $case['source']['line'];
        }
        return $source;
    }

    private static function item($case, $classification, $operation, $diagnostics) {
        $item = array(
            'id' => (string) $case['id'],
            'classification' => $classification,
            'operation' => $operation,
        );
        $source = self::source($case);
        if (null !== $source) {
            $item['source'] = $source;
        }
        $item['diagnostics'] = $diagnostics;
        return $item;
    }

    private static function unsupported_item($case, $sql, $exception) {
        return self::item(
            $case,
            'unsupported',
            self::operation($sql),
            array(
                array(
                    'code' => $exception->diagnostic_code,
                    'severity' => 'error',
                    'capability' => $exception->capability,
                    'message' => $exception->getMessage(),
                ),
            )
        );
    }

    private static function empty_counts() {
        return array(
            'native' => 0,
            'emulated' => 0,
            'expensive' => 0,
            'unsupported' => 0,
        );
    }

    /**
     * @param array<int, array{id:mixed, sql:mixed, source?:array<string,mixed>}> $cases
     * @return array<string, mixed>
     */
    public static function inspect($cases) {
        $items = array();
        $counts = self::empty_counts();

        foreach ((array) $cases as $index => $case) {
            if (!is_array($case) || !array_key_exists('id', $case) || !array_key_exists('sql', $case)) {
                throw new \InvalidArgumentException(
                    'Compatibility report cases must contain id and sql at index ' . (string) $index . '.'
                );
            }

            $sql = (string) $case['sql'];

            try {
                $plan = SqlPreflight::inspect($sql);
                if (!in_array($plan->classification, self::$classifications, true)) {
                    throw new \LogicException('Unknown WordPress compatibility classification: ' . $plan->classification);
                }
                $items[] = self::item($case, $plan->classification, $plan->operation, array());
                ++$counts[$plan->classification];
            } catch (CompatibilityException $exception) {
                $items[] = self::unsupported_item($case, $sql, $exception);
                ++$counts['unsupported'];
            }
        }

        return array(
            'version' => self::VERSION,
            'profile' => self::PROFILE,
            'compatible' => 0 === $counts['unsupported'],
            'summary' => array(
                'total' => count($items),
                'native' => $counts['native'],
                'emulated' => $counts['emulated'],
                'expensive' => $counts['expensive'],
                'unsupported' => $counts['unsupported'],
            ),
            'items' => $items,
        );
    }
}
