<?php

namespace Watchful\Backup\Step;

use RuntimeException;
use Watchful\Backup\Processor;
use Watchful\Backup\Utils;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;
use WP_Filesystem_Direct;

class DatabaseStep
{
    /** @var Utils */
    private $utils;

    /** @var Logger */
    private $logger;

    public function __construct(Utils $utils, Logger $logger)
    {
        $this->utils = $utils;
        $this->logger = $logger;
    }

    public function execute(string $backup_id, BackupState $backup_state): void
    {
        global $wpdb;

        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $chunk_config = $this->utils->calculate_chunk_size('database');
        $chunk_size = $chunk_config['chunk_size'];

        if (!$this->utils->is_chunk_processing_safe($chunk_config)) {
            return;
        }

        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($wpdb->prefix).'%'
            ),
            ARRAY_N
        );

        $current_table_index = $backup_state->database->current_table ?? 0;
        $current_offset = $backup_state->database->current_offset ?? 0;

        if (empty($backup_state->database->total_rows)) {
            $total_rows = 0;
            foreach ($tables as $table) {
                $total_rows += $wpdb->get_var("SELECT COUNT(*) FROM $table[0]");
            }
            $backup_state->database->total_rows = $total_rows;
        }

        $file_mode = $current_table_index > 0 || $current_offset > 0 ? FILE_APPEND : 0;
        $table_name = $tables[$current_table_index][0];

        if ($current_table_index >= count($tables)) {
            $backup_state->database->completed = true;
            $this->logger->info('Database backup completed', [
                'backup_id' => $backup_id,
                'total_tables' => count($tables),
            ]);

            return;
        }

        $backup_file = $this->utils->get_database_backup_file_path($backup_id, $table_name);

        $write_result = true;
        if ($current_offset === 0) {
            $write_result = $this->write_table_structure($backup_file, $table_name, $file_mode);
        }

        if ($write_result === false) {
            throw new RuntimeException('Failed to write database chunk');
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name LIMIT %d OFFSET %d",
                $chunk_size,
                $current_offset
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            $backup_state->database->current_table = $current_table_index + 1;
            $backup_state->database->current_offset = 0;

            return;
        }

        $this->write_table_data($backup_file, $table_name, $rows);

        $backup_state->database->current_offset = $current_offset + count($rows);
        $backup_state->database->rows_processed = ($backup_state->database->rows_processed ?? 0) + count($rows);

        $this->logger->debug('Database chunk processed', [
            'backup_id' => $backup_id,
            'table' => $table_name,
            'rows_processed' => count($rows),
            'current_offset' => $backup_state->database->current_offset,
        ]);
    }

    private function write_table_structure(string $backup_file, string $table_name, int $file_mode): bool
    {
        global $wpdb;

        $create_table = $wpdb->get_row("SHOW CREATE TABLE $table_name", ARRAY_N);

        $create_sql = $create_table[1];
        $table_name_without_prefix = str_replace($wpdb->prefix, Processor::DB_PREFIX_PLACEHOLDER, $table_name);
        $create_sql = str_replace($table_name, $table_name_without_prefix, $create_sql);

        if (strpos($create_sql, 'REFERENCES `'.$wpdb->prefix) !== false) {
            $create_sql = str_replace(
                'REFERENCES `'.$wpdb->prefix,
                'REFERENCES `'.Processor::DB_PREFIX_PLACEHOLDER,
                $create_sql
            );
        }

        $drop_table = "DROP TABLE IF EXISTS `$table_name_without_prefix`;\n";

        return file_put_contents(
                $backup_file,
                "-- Table: $table_name_without_prefix\n".
                $drop_table.
                $create_sql.";\n\n",
                $file_mode
            ) !== false;
    }

    private function write_table_data(string $backup_file, string $table_name, array $rows): void
    {
        global $wpdb;

        $table_name_without_prefix = str_replace($wpdb->prefix, Processor::DB_PREFIX_PLACEHOLDER, $table_name);
        $insert_statements = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'".$wpdb->_real_escape($value)."'";
                }
            }
            $insert_statements[] = '('.implode(',', $values).')';
        }

        if (!empty($insert_statements)) {
            $columns = array_keys($rows[0]);
            $columns_str = '`'.implode('`,`', $columns).'`';

            $sql = "INSERT INTO `$table_name_without_prefix` ($columns_str) VALUES\n";
            $sql .= implode(",\n", $insert_statements).";\n\n";

            $write_result = file_put_contents($backup_file, $sql, FILE_APPEND);

            if ($write_result === false) {
                throw new RuntimeException('Failed to write table data');
            }
        }
    }
}
