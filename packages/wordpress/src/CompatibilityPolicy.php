<?php

namespace Hibari\WordPress;

final class CompatibilityPolicy {
    const MODE_DEFAULT = 'default';
    const MODE_STRICT = 'strict';

    private static function count($summary, $classification) {
        return isset($summary[$classification]) ? (int) $summary[$classification] : 0;
    }

    /**
     * Evaluate an already-produced compatibility report without changing the
     * underlying preflight/runtime semantics.
     *
     * @param array<string, mixed> $report
     * @param bool                 $strict
     * @return array<string, mixed>
     */
    public static function evaluate($report, $strict = false) {
        if (!is_array($report) || !isset($report['summary']) || !is_array($report['summary'])) {
            throw new \InvalidArgumentException('Compatibility policy requires a report with a summary object.');
        }

        $summary = $report['summary'];
        $reasons = array();

        if (self::count($summary, 'unsupported') > 0) {
            $reasons[] = 'unsupported';
        }

        $complete = !array_key_exists('complete', $report) || true === $report['complete'];
        if (!$complete || self::count($summary, 'uninspectable') > 0) {
            $reasons[] = 'incomplete';
        }

        if ($strict) {
            if (self::count($summary, 'emulated') > 0) {
                $reasons[] = 'emulated';
            }
            if (self::count($summary, 'expensive') > 0) {
                $reasons[] = 'expensive';
            }
        }

        $passed = empty($reasons);
        return array(
            'mode' => $strict ? self::MODE_STRICT : self::MODE_DEFAULT,
            'passed' => $passed,
            'exitCode' => $passed ? 0 : 1,
            'reasons' => $reasons,
        );
    }
}
