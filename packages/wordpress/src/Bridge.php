<?php

namespace Hibari\WordPress;

final class SqlPlan {
    /** @var string */
    public $classification;

    /** @var string */
    public $operation;

    /** @var array<int, array<string, string>> */
    public $diagnostics;

    public function __construct($classification, $operation, $diagnostics = array()) {
        $this->classification = $classification;
        $this->operation = $operation;
        $this->diagnostics = $diagnostics;
    }
}

final class BridgeResult {
    /** @var array<int, array<string, mixed>|object> */
    public $rows;

    /** @var int */
    public $affected;

    /** @var int|null */
    public $insert_id;

    public function __construct($rows = array(), $affected = 0, $insert_id = null) {
        $this->rows = $rows;
        $this->affected = (int) $affected;
        $this->insert_id = null === $insert_id ? null : (int) $insert_id;
    }
}

interface Bridge {
    /**
     * Execute SQL that has already passed WordPress-consumer preflight.
     *
     * Concrete backend details must not appear in this contract.
     *
     * @param string  $sql
     * @param SqlPlan $plan
     * @return BridgeResult
     */
    public function execute($sql, $plan);
}

/**
 * Optional high-level bridge used when WordPress itself exposes a semantic
 * short-circuit before SQL execution (for example WP_Term_Query's
 * `terms_pre_query`). Operations are still ordinary Hibari Query/Mutation IR;
 * this interface does not expose a concrete backend or backend transport.
 */
interface OperationBridge extends Bridge {
    /**
     * @param string               $endpoint query|mutation
     * @param array<string, mixed> $operation Hibari IR payload
     * @param string               $context diagnostic context only
     * @return array<string, mixed>
     */
    public function executeOperation($endpoint, $operation, $context = '');
}

final class CompatibilityException extends \RuntimeException {
    /** @var string */
    public $diagnostic_code;

    /** @var string */
    public $capability;

    /** @var string */
    public $sql;

    public function __construct($diagnostic_code, $message, $capability, $sql) {
        parent::__construct($message);
        $this->diagnostic_code = $diagnostic_code;
        $this->capability = $capability;
        $this->sql = $sql;
    }
}
