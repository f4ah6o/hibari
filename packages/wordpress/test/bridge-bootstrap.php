<?php

namespace Hibari\WordPress\Test;

use Hibari\WordPress\Bridge;
use Hibari\WordPress\BridgeResult;

final class RecordingBridge implements Bridge {
    /** @var array<int, string> */
    public $queries = array();

    /** @var array<int, object> */
    public $plans = array();

    public function execute($sql, $plan) {
        $this->queries[] = $sql;
        $this->plans[] = $plan;

        if (
            preg_match('/\bFROM\s+wp_options\b/i', $sql)
            && preg_match("/\boption_name\s*=\s*'siteurl'/i", $sql)
        ) {
            return new BridgeResult(
                array(array('option_value' => 'https://example.test/')),
                0,
                null
            );
        }

        return new BridgeResult();
    }
}

$hibari_recording_bridge = new RecordingBridge();
$GLOBALS['hibari_wordpress_recording_bridge'] = $hibari_recording_bridge;
$GLOBALS['hibari_wordpress_bridge_factory'] = function () use ($hibari_recording_bridge) {
    return $hibari_recording_bridge;
};
