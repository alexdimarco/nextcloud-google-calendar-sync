<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20240101000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ------------------------------------------------------------------ //
        // Calendar sync mappings                                               //
        // ------------------------------------------------------------------ //
        if (!$schema->hasTable('googlecalsync_mappings')) {
            $table = $schema->createTable('googlecalsync_mappings');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // 'one_to_one' or 'many_to_one'
            $table->addColumn('mode', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'one_to_one',
            ]);
            // JSON array of Google calendar IDs
            $table->addColumn('google_calendar_ids', Types::TEXT, [
                'notnull' => true,
                'default' => '[]',
            ]);
            // Nextcloud calendar URI (e.g. "personal")
            $table->addColumn('nextcloud_calendar', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('read_only', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('sync_interval_minutes', Types::INTEGER, [
                'notnull' => true,
                'default' => 5,
            ]);
            $table->addColumn('last_synced_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            // Google incremental sync token for delta syncs
            $table->addColumn('google_sync_token', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'gcs_mappings_user_idx');
        }

        // ------------------------------------------------------------------ //
        // UID → Google Event ID mapping (for bidirectional push dedup)         //
        // ------------------------------------------------------------------ //
        if (!$schema->hasTable('googlecalsync_uid_map')) {
            $table = $schema->createTable('googlecalsync_uid_map');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('nc_uid', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('google_id', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['nc_uid'], 'gcs_uid_map_uid_uq');
        }

        return $schema;
    }
}
