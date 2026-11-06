<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261106000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('extkal_accounts')) {
			$t = $schema->createTable('extkal_accounts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 2000]);
			$t->addColumn('username', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('password', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'extkal_acc_user');
		}

		if (!$schema->hasTable('extkal_bindings')) {
			$t = $schema->createTable('extkal_bindings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('account_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('calendar_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('remote_url', Types::STRING, ['notnull' => true, 'length' => 2000]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('readonly', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('error_since', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('notified', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['account_id'], 'extkal_bind_acc');
			$t->addIndex(['calendar_id'], 'extkal_bind_cal');
		}

		if (!$schema->hasTable('extkal_objects')) {
			$t = $schema->createTable('extkal_objects');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('binding_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('local_uri', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('remote_href', Types::STRING, ['notnull' => true, 'length' => 2000]);
			$t->addColumn('remote_etag', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('local_etag', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['binding_id'], 'extkal_obj_bind');
		}
		return $schema;
	}
}
