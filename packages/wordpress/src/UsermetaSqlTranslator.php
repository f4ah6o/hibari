<?php

namespace Hibari\WordPress;

/**
 * Stock wp_usermeta Metadata API SQL -> generic Dynamic Attributes IR.
 */
final class UsermetaSqlTranslator {
    public static function translate($sql) {
        return MetadataSqlTranslator::translate(
            $sql,
            array(
                'tableSuffix' => 'usermeta',
                'model' => 'UserMeta',
                'ownerColumn' => 'user_id',
                'idColumn' => 'umeta_id',
                'diagnosticCode' => 'HIB-WP-USERMETA-001',
                'label' => 'wp_usermeta',
            )
        );
    }
}
