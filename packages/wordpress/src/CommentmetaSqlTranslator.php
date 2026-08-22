<?php

namespace Hibari\WordPress;

final class CommentmetaSqlTranslator {
    public static function translate($sql) {
        return MetadataSqlTranslator::translate(
            $sql,
            array(
                'tableSuffix' => 'commentmeta',
                'model' => 'CommentMeta',
                'ownerColumn' => 'comment_id',
                'idColumn' => 'meta_id',
                'diagnosticCode' => 'HIB-WP-COMMENTMETA-001',
                'label' => 'wp_commentmeta',
            )
        );
    }
}
