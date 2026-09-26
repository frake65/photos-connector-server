<?php
declare(strict_types=1);
namespace OCA\ApplePhotosConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist successful idempotent Inventory v2 responses atomically with their runs. */
final class Version008800Date20260925000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('apc_inventory_idempotency')) { return $schema; }
        $table = $schema->createTable('apc_inventory_idempotency');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('idempotency_key', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('source_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('result_json', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id', 'idempotency_key'], 'apc_inventory_idem_key');
        $table->addIndex(['user_id', 'source_id'], 'apc_inventory_idem_source');
        return $schema;
    }
}
