<?php

namespace Watchful\Restore;

class RestoreDownloader
{
    public static function receive(string $destination): bool
    {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!isset($data['url'], $data['range_start'], $data['range_end'])) {
            http_response_code(400);
            echo "Missing required fields: url, range_start, range_end";

            return false;
        }

        $url = $data['url'];
        $rangeStart = (int)$data['range_start'];
        $rangeEnd = (int)$data['range_end'];

        if ($rangeStart < 0 || $rangeEnd <= $rangeStart) {
            http_response_code(400);
            echo "Invalid range values";

            return false;
        }

        $outputDir = dirname($destination);
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0775, true)) {
                error_log("[RESTORE] Failed to create output directory: $outputDir");

                return false;
            }
        }

        $chunkIndex = str_pad((string)$rangeStart, 10, '0', STR_PAD_LEFT);
        $tempChunkPath = $destination.".part{$chunkIndex}";

        $context = stream_context_create([
                                             'http' => [
                                                 'method' => 'GET',
                                                 'header' => "Range: bytes={$rangeStart}-{$rangeEnd}",
                                             ],
                                         ]);

        $remoteStream = fopen($url, 'rb', false, $context);
        if (!$remoteStream) {
            error_log("[RESTORE] Failed to open remote stream");

            return false;
        }

        $outputFile = fopen($tempChunkPath, 'wb');
        if (!$outputFile) {
            error_log("[RESTORE] Failed to open local chunk file: $tempChunkPath");
            fclose($remoteStream);

            return false;
        }

        while (!feof($remoteStream)) {
            $buffer = fread($remoteStream, 8192);
            fwrite($outputFile, $buffer);
        }

        fclose($remoteStream);
        fclose($outputFile);

        return true;
    }
}
