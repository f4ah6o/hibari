<?php

namespace Hibari\WordPress;

final class HttpBridge implements OperationBridge {
    /** @var string */
    private $base_url;

    /** @var float */
    private $timeout;

    public function __construct($base_url, $timeout = 5.0) {
        $this->base_url = rtrim((string) $base_url, '/');
        $this->timeout = (float) $timeout;
    }

    private function request($path, $operation, $context) {
        $payload = json_encode($operation);
        if (false === $payload) {
            throw new CompatibilityException(
                'HIB-WP-SQL-001',
                'Unable to serialize translated Hibari operation.',
                'wordpress.runtime.transport',
                $context
            );
        }

        $http_context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nConnection: close\r\n",
                'content' => $payload,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ),
        ));

        $body = @file_get_contents($this->base_url . $path, false, $http_context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }

        if (false === $body) {
            throw new CompatibilityException(
                'HIB-WP-RUNTIME-001',
                'Unable to reach the Hibari runtime transport.',
                'wordpress.runtime.transport',
                $context
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new CompatibilityException(
                'HIB-WP-RUNTIME-002',
                'Hibari runtime returned an invalid JSON response.',
                'wordpress.runtime.transport',
                $context
            );
        }

        if ($status < 200 || $status >= 300) {
            $diagnostic = isset($decoded['error']['diagnostics'][0]) && is_array($decoded['error']['diagnostics'][0])
                ? $decoded['error']['diagnostics'][0]
                : array();
            throw new CompatibilityException(
                isset($diagnostic['code']) ? $diagnostic['code'] : 'HIB-WP-RUNTIME-003',
                isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Hibari runtime request failed.',
                isset($diagnostic['capability']) ? $diagnostic['capability'] : 'wordpress.runtime.transport',
                $context
            );
        }

        return $decoded;
    }

    public function executeOperation($endpoint, $operation, $context = '') {
        if ('query' !== $endpoint && 'mutation' !== $endpoint) {
            throw new CompatibilityException(
                'HIB-WP-RUNTIME-004',
                'Unsupported Hibari runtime endpoint requested by the WordPress adapter.',
                'wordpress.runtime.transport',
                $context
            );
        }
        return $this->request('/v1/' . $endpoint, $operation, $context);
    }

    public function execute($sql, $plan) {
        $semantic = TaxonomySqlTranslator::executeSemantic($sql, $this);
        if ($semantic instanceof BridgeResult) {
            return $semantic;
        }

        $translation = OptionPreloadSqlTranslator::translate($sql);
        if (null === $translation) {
            $translation = PostmetaSqlTranslator::translate($sql);
        }
        if (null === $translation) {
            $translation = UsermetaSqlTranslator::translate($sql);
        }
        if (null === $translation) {
            $translation = UserSqlTranslator::translate($sql);
        }
        if (null === $translation) {
            $translation = TaxonomySqlTranslator::translate($sql);
        }
        if (null === $translation) {
            $translation = WordPressSqlTranslator::translate($sql);
        }
        $decoded = $this->executeOperation($translation->endpoint, $translation->operation, $sql);

        $rows = array();
        foreach (isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : array() as $record) {
            if (!is_array($record)) {
                continue;
            }
            $row = array();
            foreach ($translation->columns as $field => $column) {
                $row[$column] = array_key_exists($field, $record) ? $record[$field] : null;
            }
            $rows[] = $row;
        }

        $insert_id = null;
        if (isset($decoded['ids'][0])) {
            $insert_id = (int) $decoded['ids'][0];
        }

        return new BridgeResult(
            $rows,
            isset($decoded['affected']) ? (int) $decoded['affected'] : 0,
            $insert_id
        );
    }
}
