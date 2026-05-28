<?php

namespace Watchful\Restore\Step;

use Exception;
use RuntimeException;
use Watchful\Backup\Processor;
use Watchful\Backup\Utils;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\DirectoryHelper;
use Watchful\Restore\StepResponse;
use wpdb;
use Watchful\Helpers\PhpFilesystem;

class RestoreDatabaseStep implements StepInterface
{
    private $logger;
    /** @var DirectoryHelper $directory_helper */
    private $directory_helper;

    public function __construct(Files $file_helper, Logger $logger)
    {
        $this->logger = $logger;
        $this->directory_helper = new DirectoryHelper($file_helper, $logger);
    }

    public function run(string $backup_id, array $data): StepResponse
    {
        global $wpdb;

        $database_dir = $this->directory_helper->get_database_restore_directory($backup_id);

        $this->logger->debug('Restore database', [
            'backup_id' => $backup_id,
            'database_dir' => $database_dir,
            'data' => $data,
        ]);

        if (empty($data)) {
            $this->unzip_sql_files($backup_id);
        }

        $stats = empty($data) ? [
            'total_queries' => 0,
            'executed_queries' => 0,
            'errors' => [],
            'transactions' => [],
            'current_table' => null,
        ] : $data;

        $current_table = $this->get_current_table($backup_id, $stats['current_table']);
        $stats['current_table'] = $current_table;

        $this->logger->debug('Database restore stats', [
            'stats' => $stats,
        ]);

        if ($current_table === null) {
            throw new RuntimeException('No SQL files found');
        }

        $sql_file = $database_dir.DIRECTORY_SEPARATOR.$current_table.'.sql';

        $handler = @PhpFilesystem::fopen($sql_file, 'r');

        if ($handler === false) {
            $this->logger->error('Failed to open SQL file', [
                'file_path' => $sql_file,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_RESTORE_DATABASE_FILE_NOT_FOUND
            );
        }

        $buffer = '';
        $in_transaction = false;

        while (($line = fgets($handler)) !== false) {
            $line = trim($line);

            if (empty($line) || preg_match('/^--/', $line) || preg_match('/^#/', $line)) {
                continue;
            }

            if (preg_match('/^START\s+TRANSACTION/i', $line)) {
                $in_transaction = true;
                $this->query('START TRANSACTION', $wpdb);
                $stats['total_queries']++;
                $stats['executed_queries']++;
                continue;
            }

            if (preg_match('/^COMMIT/i', $line)) {
                if ($in_transaction) {
                    $this->query('COMMIT', $wpdb);
                    $stats['total_queries']++;
                    $stats['executed_queries']++;
                    $in_transaction = false;
                }
                continue;
            }

            $buffer .= $line.' ';

            if (substr($line, -1) !== ';') {
                continue;
            }

            $stats['total_queries']++;
            $query = $this->search_and_replace_sql($buffer);
            $query_log = strlen($query) > 500 ? substr($query, 0, 500).'...' : $query;

            try {
                $this->query('SET FOREIGN_KEY_CHECKS=0', $wpdb);
                $result = $this->query($query, $wpdb);
                $this->query('SET FOREIGN_KEY_CHECKS=1', $wpdb);
            } catch (Exception $e) {
                $result = false;
            }
            $buffer = '';

            if ($result !== false) {
                $stats['executed_queries']++;
                continue;
            }

            $this->logger->error('Error executing SQL query', [
                'error' => $wpdb->last_error,
                'exception' => isset($e) ? $e->getMessage() : null,
                'query' => $query_log,
            ]);

            $stats['errors'][] = [
                'error' => $wpdb->last_error,
                'query' => $query_log,
            ];

            if ($in_transaction) {
                $this->query('ROLLBACK', $wpdb);
                $in_transaction = false;
            }
        }

        @PhpFilesystem::fclose($handler);
        wp_delete_file($sql_file);

        $db_restoration_completed = $this->get_current_table($backup_id, $stats['current_table']) === null;

        if (count($stats['errors']) > 0) {
            $this->logger->error('Database restore completed with errors', [
                'errors' => $stats['errors'],
            ]);

            return new StepResponse(
                $db_restoration_completed,
                StepResponse::STATUS_CODE_RESTORE_DATABASE_ERRORS,
                $stats
            );
        }

        $this->logger->info('Database restore completed successfully', [
            'stats' => $stats,
        ]);

        return new StepResponse(
            $db_restoration_completed,
            $db_restoration_completed ? StepResponse::STATUS_CODE_RESTORE_DATABASE_COMPLETED : StepResponse::STATUS_CODE_RESTORE_DATABASE_PROCESSING,
            $stats
        );
    }

    private function unzip_sql_files(string $backup_id): void
    {
        $database_dir = $this->directory_helper->get_database_restore_directory($backup_id);
        $zip = $this->directory_helper->get_zip_archive($backup_id);

        $zip_dir = Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_ARCHIVE_DATABASE_DIR_NAME;
        $this->logger->info('Unzip SQL files', [
            'backup_id' => $backup_id,
            'database_dir' => $database_dir,

        ]);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $zip_entry = $stat['name'];

            if (strpos($zip_entry, $zip_dir) !== 0 || substr($zip_entry, -1) === '/') {
                continue;
            }

            $stream = $zip->getStream($zip_entry);
            $destination_path = $database_dir.DIRECTORY_SEPARATOR.str_replace($zip_dir, '', $zip_entry);

            @file_put_contents($destination_path, stream_get_contents($stream));
            @PhpFilesystem::fclose($stream);

            $this->logger->info('Unzipped SQL file', [
                'destination_path' => $destination_path,
                'name' => $zip_entry,
            ]);
        }

        $zip->close();
    }

    private function get_current_table(string $backup_id, $previous_table_name): ?string
    {
        $database_dir = $this->directory_helper->get_database_restore_directory($backup_id);
        $sql_files = glob($database_dir.DIRECTORY_SEPARATOR.'*.sql');
        $current_table_name = null;

        $this->logger->debug('Get current table', [
            'backup_id' => $backup_id,
            'sql_files' => $sql_files,
            'previous_table_name' => $previous_table_name,
        ]);

        foreach ($sql_files as $sql_file) {
            $table_name = basename($sql_file, '.sql');

            if ($previous_table_name === null) {
                $this->logger->debug('Set current table', [
                    'backup_id' => $backup_id,
                    'table_name' => $table_name,
                ]);

                return $table_name;
            }

            if ($table_name === $previous_table_name) {
                continue;
            }

            $current_table_name = $table_name;
            break;
        }

        if ($current_table_name === null) {
            $this->logger->debug('No more tables to restore');
        }

        return $current_table_name;
    }

    private function query(string $query, wpdb $wpdb, bool $retry = true)
    {
        $query_success = mysqli_real_query($wpdb->dbh, $query); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_query -- Direct mysqli required for non-buffered bulk SQL restore; $wpdb overhead is unsuitable here.

        if ($query_success) {
            return true;
        }

        if (empty($wpdb->dbh)) {
            if ($wpdb->check_connection(false) === false || $retry === false) {
                $this->logger->error('MySQL server has gone away and cannot be reconnected.', [
                    'query' => $query,
                ]);
                throw new RuntimeException('MySQL server has gone away.');
            }

            $this->logger->warning('MySQL server has gone away. Reconnected and retrying query.', [
                'query' => $query,
            ]);

            return $this->query($query, $wpdb, false);
        }

        $context = [
            'query' => $query,
            'error' => mysqli_errno($wpdb->dbh), // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Direct mysqli required for non-buffered bulk SQL restore.
            'error_message' => mysqli_error($wpdb->dbh), // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error -- Direct mysqli required for non-buffered bulk SQL restore.
        ];

        if ($retry === false) {
            $this->logger->error('MySQL query failed.', $context);
        }

        $this->logger->warning('MySQL query failed, retrying.', $context);

        return $this->query($query, $wpdb, false);
    }

    private function search_and_replace_sql(string $sql): string
    {
        global $wpdb;

        $entities = [
            [
                'search' => Processor::DB_PREFIX_PLACEHOLDER,
                'replace' => $wpdb->prefix,
            ],
        ];

        foreach ($entities as $entity) {
            $sql = str_replace($entity['search'], $entity['replace'], $sql);
        }

        return $sql;
    }
}
