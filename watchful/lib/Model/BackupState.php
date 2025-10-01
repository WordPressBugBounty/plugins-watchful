<?php

namespace Watchful\Model;

use JsonSerializable;

class BackupState implements JsonSerializable
{
    /** @var string */
    public $status = 'pending';
    /** @var int */
    public $completed_at = 0;

    /** @var array */
    public $errors = [];

    /** @var BackupManifest */
    public $manifest;

    /** @var FileEnumerationStep */
    public $file_enumeration;
    /** @var DatabaseStep */
    public $database;
    /** @var ArchiveStep */
    public $archive;
    /** @var UploadStep */
    public $upload;
    /** @var VerificationStep */
    public $verification;
    /** @var CleanupStep */
    public $cleanup;

    public function __construct()
    {
        $this->manifest = new BackupManifest();
        $this->file_enumeration = new FileEnumerationStep();
        $this->database = new DatabaseStep();
        $this->archive = new ArchiveStep();
        $this->upload = new UploadStep();
        $this->verification = new VerificationStep();
        $this->cleanup = new CleanupStep();
    }

    public static function fromArray(array $data): self
    {
        $state = new self();
        $state->status = $data['status'];
        $state->completed_at = $data['completed_at'] ?? 0;
        $state->errors = $data['errors'] ?? [];

        foreach (
            [
                'manifest' => BackupManifest::class,
                'file_enumeration' => FileEnumerationStep::class,
                'database' => DatabaseStep::class,
                'archive' => ArchiveStep::class,
                'upload' => UploadStep::class,
                'verification' => VerificationStep::class,
                'cleanup' => CleanupStep::class,
            ] as $key => $class
        ) {
            if (isset($data[$key])) {
                $state->{$key} = new $class();
                foreach ($data[$key] as $property => $value) {
                    if ($property === 'parts' && $key === 'upload') {
                        $state->{$key}->{$property} = array_map(
                            function ($part) {
                                return UploadPart::fromArray($part);
                            },
                            $value
                        );
                        continue;
                    }
                    if (property_exists($state->{$key}, $property)) {
                        $state->{$key}->{$property} = $value;
                    }
                }
            }
        }

        return $state;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'completed_at' => $this->completed_at,
            'errors' => $this->errors,
            'manifest' => $this->manifest,
            'file_enumeration' => $this->file_enumeration,
            'database' => $this->database,
            'archive' => $this->archive,
            'upload' => $this->upload,
            'verification' => $this->verification,
            'cleanup' => $this->cleanup,
        ];
    }
}

