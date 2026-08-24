<?php

namespace Hibari\WordPress;

/**
 * Static, execution-free scanner for common direct $wpdb SQL calls.
 *
 * The scanner intentionally understands only literal SQL. Anything requiring
 * PHP execution is reported as uninspectable rather than guessed or ignored.
 */
final class PluginCompatibilityScanner {
    const VERSION = 1;
    const PROFILE = 'wordpress-plugin-static-v0';
    const UNINSPECTABLE_CODE = 'HIB-WP-SCAN-001';

    private static $methods = array(
        'query',
        'get_results',
        'get_row',
        'get_col',
        'get_var',
    );

    private static function ignorable($token) {
        return is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
    }

    private static function next_significant($tokens, $index) {
        $count = count($tokens);
        while ($index < $count && self::ignorable($tokens[$index])) {
            ++$index;
        }
        return $index < $count ? $index : null;
    }

    private static function first_argument($tokens, $open_index) {
        $argument = array();
        $depth = 0;
        $count = count($tokens);

        for ($index = $open_index + 1; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ('(' === $token) {
                ++$depth;
                $argument[] = $token;
                continue;
            }
            if (')' === $token) {
                if (0 === $depth) {
                    break;
                }
                --$depth;
                $argument[] = $token;
                continue;
            }
            if (',' === $token && 0 === $depth) {
                break;
            }
            $argument[] = $token;
        }

        return $argument;
    }

    private static function decode_literal($literal) {
        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }

        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $inner = substr($literal, 1, -1);
        if ('"' === $quote) {
            return stripcslashes($inner);
        }

        $decoded = '';
        $inner_length = strlen($inner);
        for ($index = 0; $index < $inner_length; ++$index) {
            $char = $inner[$index];
            if ('\\' === $char && $index + 1 < $inner_length) {
                $next = $inner[$index + 1];
                if ('\\' === $next || "'" === $next) {
                    $decoded .= $next;
                    ++$index;
                    continue;
                }
            }
            $decoded .= $char;
        }
        return $decoded;
    }

    private static function static_literal($tokens) {
        $significant = array_values(array_filter($tokens, function ($token) {
            return !self::ignorable($token);
        }));

        if (empty($significant)) {
            return null;
        }

        $parts = array();
        $expect_literal = true;
        foreach ($significant as $token) {
            if ($expect_literal) {
                if (!is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                    return null;
                }
                $decoded = self::decode_literal($token[1]);
                if (null === $decoded) {
                    return null;
                }
                $parts[] = $decoded;
                $expect_literal = false;
                continue;
            }

            if ('.' !== $token) {
                return null;
            }
            $expect_literal = true;
        }

        if ($expect_literal) {
            return null;
        }

        return implode('', $parts);
    }

    private static function php_files($root) {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private static function relative_path($root, $path) {
        $relative = substr($path, strlen($root) + 1);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private static function scan_file($root, $path, &$cases, &$diagnostics) {
        $source = file_get_contents($path);
        if (false === $source) {
            throw new \RuntimeException('Unable to read plugin source: ' . $path);
        }

        $tokens = token_get_all($source);
        $relative = self::relative_path($root, $path);
        $call_index = 0;
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1]) {
                continue;
            }

            $operator_index = self::next_significant($tokens, $index + 1);
            if (null === $operator_index) {
                continue;
            }
            $operator = $tokens[$operator_index];
            if (!is_array($operator) || T_OBJECT_OPERATOR !== $operator[0]) {
                continue;
            }

            $method_index = self::next_significant($tokens, $operator_index + 1);
            if (null === $method_index) {
                continue;
            }
            $method_token = $tokens[$method_index];
            if (!is_array($method_token) || T_STRING !== $method_token[0]) {
                continue;
            }
            $method = strtolower($method_token[1]);
            if (!in_array($method, self::$methods, true)) {
                continue;
            }

            $open_index = self::next_significant($tokens, $method_index + 1);
            if (null === $open_index || '(' !== $tokens[$open_index]) {
                continue;
            }

            ++$call_index;
            $line = (int) $token[2];
            $id = $relative . ':' . $line . ':' . $method . ':' . $call_index;
            $sql = self::static_literal(self::first_argument($tokens, $open_index));

            if (null === $sql) {
                $diagnostics[] = array(
                    'id' => $id,
                    'code' => self::UNINSPECTABLE_CODE,
                    'severity' => 'warning',
                    'capability' => 'wordpress.staticSql',
                    'message' => 'SQL argument for $wpdb->' . $method . '() is not a static literal and cannot be inspected without execution.',
                    'source' => array(
                        'file' => $relative,
                        'line' => $line,
                    ),
                );
                continue;
            }

            $cases[] = array(
                'id' => $id,
                'sql' => $sql,
                'source' => array(
                    'file' => $relative,
                    'line' => $line,
                ),
            );
        }
    }

    /**
     * @param string $directory
     * @return array<string, mixed>
     */
    public static function inspectDirectory($directory) {
        $root = realpath((string) $directory);
        if (false === $root || !is_dir($root)) {
            throw new \InvalidArgumentException('Plugin source directory does not exist: ' . (string) $directory);
        }

        $files = self::php_files($root);
        $cases = array();
        $diagnostics = array();
        foreach ($files as $path) {
            self::scan_file($root, $path, $cases, $diagnostics);
        }

        $report = CompatibilityReport::inspect($cases);
        $complete = empty($diagnostics);

        return array(
            'version' => self::VERSION,
            'profile' => self::PROFILE,
            'complete' => $complete,
            'compatible' => $complete && $report['compatible'],
            'summary' => array(
                'files' => count($files),
                'sqlCases' => $report['summary']['total'],
                'portable' => $report['summary']['portable'],
                'unsupported' => $report['summary']['unsupported'],
                'uninspectable' => count($diagnostics),
            ),
            'items' => $report['items'],
            'diagnostics' => $diagnostics,
        );
    }
}
