<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** The strict, repeatable schema gate for the traffic and commission changes. */
class PerformanceSchema
{
    private const INDEXES = [
        'v2_commission_log' => [
            'trade_inviter' => ['unique', ['trade_no', 'invite_user_id']],
            'idx_commission_created_at' => ['index', ['created_at']],
        ],
        'v2_order' => [
            'idx_status_created' => ['index', ['status', 'created_at', 'id']],
            'idx_commission_queue' => ['index', ['commission_status', 'status', 'updated_at', 'id']],
            'idx_created_status' => ['index', ['created_at', 'status']],
            'idx_paid_status' => ['index', ['paid_at', 'status']],
        ],
        'v2_stat_server' => ['idx_type_record' => ['index', ['record_type', 'record_at']]],
        'v2_stat_user' => ['idx_type_record_user' => ['index', ['record_type', 'record_at', 'user_id']]],
        'v2_ticket' => ['idx_autoclose' => ['index', ['status', 'reply_status', 'updated_at', 'id']]],
        'v2_user' => [
            'idx_group_access' => ['index', ['group_id', 'banned', 'expired_at', 'id']],
            'idx_auto_renewal' => ['index', ['auto_renewal', 'expired_at', 'id']],
            'idx_last_traffic' => ['index', ['t']],
            'idx_created_invite' => ['index', ['created_at', 'invite_user_id']],
        ],
    ];

    public static function ensure(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS `v2_node_report` (' .
            '`report_id` char(32) NOT NULL PRIMARY KEY, `server_id` int NOT NULL,' .
            '`server_type` char(11) NOT NULL, `created_at` int NOT NULL,' .
            'KEY `created_at` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        DB::statement('CREATE TABLE IF NOT EXISTS `v2_traffic_batch` (' .
            '`batch_id` char(32) NOT NULL PRIMARY KEY, `created_at` int NOT NULL,' .
            'KEY `created_at` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        foreach (['traffic_reset_at' => 'bigint NOT NULL DEFAULT 0',
                  'traffic_reset_cycle' => 'int NOT NULL DEFAULT 0'] as $column => $definition) {
            if (!Schema::hasColumn('v2_user', $column)) {
                DB::statement('ALTER TABLE `v2_user` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        $duplicate = DB::selectOne('SELECT trade_no, invite_user_id FROM v2_commission_log ' .
            'GROUP BY trade_no, invite_user_id HAVING COUNT(*) > 1 LIMIT 1');
        if ($duplicate) {
            throw new RuntimeException('Duplicate commission logs prevent the trade_inviter unique index');
        }

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => [$kind, $columns]) {
                $existing = self::index($table, $name);
                if (!$existing) {
                    $quoted = array_map(function ($column) { return '`' . $column . '`'; }, $columns);
                    DB::statement('ALTER TABLE `' . $table . '` ADD ' .
                        ($kind === 'unique' ? 'UNIQUE ' : '') . 'INDEX `' . $name . '` (' .
                        implode(',', $quoted) . ')');
                    $existing = self::index($table, $name);
                }
                if (!$existing || $existing['columns'] !== $columns || $existing['unique'] !== ($kind === 'unique')) {
                    throw new RuntimeException("Unexpected definition for {$table}.{$name}");
                }
            }
        }

        foreach (['v2_node_report' => 'report_id', 'v2_traffic_batch' => 'batch_id'] as $table => $id) {
            foreach ([$id, 'created_at'] as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Missing {$table}.{$column}");
                }
            }
            $primary = self::index($table, 'PRIMARY');
            if (!$primary || $primary['columns'] !== [$id] || !$primary['unique']) {
                throw new RuntimeException("Missing primary key on {$table}.{$id}");
            }
            $idColumn = DB::selectOne('SHOW COLUMNS FROM `' . $table . '` WHERE Field = ?', [$id]);
            if (!$idColumn || strtolower($idColumn->Type) !== 'char(32)') {
                throw new RuntimeException("Unexpected type for {$table}.{$id}");
            }
        }
        foreach (['traffic_reset_at' => 'bigint', 'traffic_reset_cycle' => 'int'] as $column => $type) {
            if (!Schema::hasColumn('v2_user', $column)) {
                throw new RuntimeException("Missing v2_user.{$column}");
            }
            $definition = DB::selectOne('SHOW COLUMNS FROM `v2_user` WHERE Field = ?', [$column]);
            if (!$definition || strpos(strtolower($definition->Type), $type) !== 0) {
                throw new RuntimeException("Unexpected type for v2_user.{$column}");
            }
        }
        foreach (['v2_node_report', 'v2_traffic_batch', 'v2_user', 'v2_stat_user', 'v2_stat_server'] as $table) {
            $metadata = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (!$metadata || strcasecmp($metadata->engine, 'InnoDB') !== 0) {
                throw new RuntimeException("Transactions require InnoDB for {$table}");
            }
        }
    }

    private static function index(string $table, string $name): ?array
    {
        $rows = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$name]);
        if (!$rows) {
            return null;
        }
        usort($rows, function ($a, $b) { return (int)$a->Seq_in_index <=> (int)$b->Seq_in_index; });
        return [
            'columns' => array_map(function ($row) { return $row->Column_name; }, $rows),
            'unique' => (int)$rows[0]->Non_unique === 0,
        ];
    }
}
