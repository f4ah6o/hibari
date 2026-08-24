<?php

throw new RuntimeException('SCANNED_PLUGIN_EXECUTED');

function hibari_fixture_queries($wpdb, $dynamic) {
    $wpdb->get_var(
        "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1"
    );

    $wpdb->get_results(
        'SELECT p.ID FROM wp_posts p ' .
        'JOIN wp_postmeta m ON m.post_id = p.ID'
    );

    $wpdb->query($dynamic);
}
