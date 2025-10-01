<?php

namespace Watchful\Backup;

use Exception;
use Watchful\Helpers\Logger;
use Watchful\Model\UploadPart;
use Watchful\Model\UploadStep;

final class ChunkedUploader
{
    /** @var Logger */
    private $logger;
    private $chunkSize;

    public function __construct(?int $chunkSize = null)
    {
        $this->logger = new Logger('backup');
        $this->chunkSize = $chunkSize ?? 5 * 1024 * 1024;
    }


    /**
     * @throws Exception
     */
    public function upload_file(string $filePath, ?string $url, UploadStep $uploadStep)
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('File not found', ['file' => $filePath]);
            throw new Exception('File not found');
        }

        $handle = fopen($filePath, 'rb');

        if ($uploadStep->total_size === 0) {
            $uploadStep->total_size = filesize($filePath);
        }

        if ($uploadStep->total_parts === 0) {
            $uploadStep->total_parts = ceil($uploadStep->total_size / $this->chunkSize);
        }

        if (empty($url)) {
            return;
        }

        fseek($handle, $uploadStep->current_offset);

        $chunk = fread($handle, $this->chunkSize);
        if ($chunk !== false) {
            $response = $this->upload_part(
                $url,
                $chunk
            );

            $uploadStep->part_number++;

            $uploadPart = new UploadPart();
            $uploadPart->part_number = $uploadStep->part_number;
            $uploadPart->e_tag = $this->extract_e_tag($response);

            $uploadStep->parts[] = $uploadPart;

            $uploadStep->current_offset = ftell($handle);
        }

        if (feof($handle)) {
            $uploadStep->completed = true;
        }

        fclose($handle);
    }

    /**
     * @throws Exception
     */
    private function upload_part(
        string $url,
        string $data
    ): array {
        $this->logger->debug('Uploading part', ['url' => $url]);

        /** @var array|null $response */
        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => ini_get('max_execution_time') * 0.8,
            'headers' => ['Content-Length' => strlen($data)],
            'body' => $data,
        ]);

        if ($response === null || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->logger->warning('The part could not be uploaded', [
                'response' => $response,
                'response_message' => wp_remote_retrieve_response_message($response),
                'body' => wp_remote_retrieve_body($response),
                'url' => $url,
            ]);

            throw new Exception(
                "The part could not be uploaded: ".wp_remote_retrieve_response_message(
                    $response
                ).' '.wp_remote_retrieve_body($response).' URL: '.$url
            );
        }

        return $response;
    }

    /**
     * @throws Exception
     */
    private function extract_e_tag(array $response): string
    {
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['etag'])) {
            return trim($headers['etag'], '"');
        }
        throw new Exception('ETag not found in response');
    }
}