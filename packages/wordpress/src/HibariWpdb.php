<?php

namespace Hibari\WordPress;

class HibariWpdb extends \wpdb {
    /** @var Bridge */
    private $hibari_bridge;

    public function __construct($bridge) {
        $this->hibari_bridge = $bridge;

        // Intentionally do not invoke wpdb::__construct(): that constructor
        // establishes a MySQL connection. The drop-in owns execution instead.
        $this->ready = true;
        $this->is_mysql = false;
        $this->dbh = null;
        $this->result = null;
        $this->charset = 'utf8mb4';
        $this->collate = '';
        $this->last_error = '';
        $this->last_result = array();
        $this->num_rows = 0;
        $this->rows_affected = 0;
        $this->insert_id = 0;
        $this->check_current_query = false;
    }

    /**
     * wpdb::prepare() normally delegates to mysqli_real_escape_string().
     * A database drop-in has no mysqli handle, so escape deterministically
     * and preserve wpdb's placeholder escape contract.
     */
    public function _real_escape($data) {
        if (!is_scalar($data)) {
            return '';
        }

        return $this->add_placeholder_escape(addslashes((string) $data));
    }

    /**
     * Route wpdb operations into the backend-neutral Hibari bridge.
     *
     * @param string $query
     * @return int|bool
     */
    public function query($query) {
        if (!is_string($query) || '' === trim($query)) {
            return false;
        }

        $this->last_query = $query;
        $this->last_error = '';
        $this->last_result = array();
        $this->num_rows = 0;
        $this->rows_affected = 0;
        ++$this->num_queries;

        $plan = SqlPreflight::inspect($query);
        $result = $this->hibari_bridge->execute($query, $plan);

        if (!$result instanceof BridgeResult) {
            throw new \RuntimeException('Hibari WordPress bridge must return BridgeResult.');
        }

        foreach ($result->rows as $row) {
            $this->last_result[] = is_object($row) ? $row : (object) $row;
        }

        $this->num_rows = count($this->last_result);
        $this->rows_affected = (int) $result->affected;
        $this->insert_id = null === $result->insert_id ? 0 : (int) $result->insert_id;

        if ('select' === $plan->operation) {
            return $this->num_rows;
        }

        return $this->rows_affected;
    }
}
