<?php

namespace Hibari\WordPress;

/**
 * Stock wp_postmeta Metadata API SQL -> generic Dynamic Attributes IR.
 */
final class PostmetaSqlTranslator {
    public static function translate($sql) {
        return MetadataSqlTranslator::translate(
            $sql,
            array(
                'tableSuffix' => 'postmeta',
                'model' => 'PostMeta',
                'ownerColumn' => 'post_id',
                'idColumn' => 'meta_id',
                'diagnosticCode' => 'HIB-WP-POSTMETA-001',
                'label' => 'wp_postmeta',
            )
        );
    }
}
