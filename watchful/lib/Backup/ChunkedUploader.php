<?php

namespace Watchful\Backup;

use Exception;
use Watchful\Helpers\Logger;

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
    public function upload_file(string $filePath, ?string $url, ?array $resumeData = null): array
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('File not found', ['file' => $filePath]);
            throw new Exception('File not found');
        }

        $handle = fopen($filePath, 'rb');

        $state = $resumeData ?? [
            'completed' => true,
            'current_offset' => 0,
            'part_number' => 0,
            'total_size' => filesize($filePath),
            'total_parts' => ceil(filesize($filePath) / $this->chunkSize),
            'parts' => [],
        ];

        if (empty($resumeData['total_size'])) {
            $state['total_size'] = filesize($filePath);
        }

        if (empty($resumeData['total_parts'])) {
            $state['total_parts'] = ceil(filesize($filePath) / $this->chunkSize);
        }

        if (empty($url)) {
            return $state;
        }

        fseek($handle, $state['current_offset']);

        $chunk = fread($handle, $this->chunkSize);
        if ($chunk !== false) {
            $response = $this->upload_part(
                $url,
                $chunk
            );

            $state['part_number']++;
            $state['parts'][] = [
                'PartNumber' => $state['part_number'],
                'ETag' => $this->extract_e_tag($response),
            ];

            $state['current_offset'] = ftell($handle);
        }

        if (feof($handle)) {
            $state['completed'] = true;
        }

        fclose($handle);

        return $state;
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