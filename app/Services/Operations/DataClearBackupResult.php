<?php

namespace App\Services\Operations;

/** Outcome of DataClearBackupService::backupAndVerify() — never throws, always returns one of these so DataClearService can decide what to do next. */
final class DataClearBackupResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $reason,
        public readonly ?string $path,
        public readonly ?int $sizeBytes,
        /** @var array<string,int> table => row count captured at dump time */
        public readonly array $preClearRowCounts,
    ) {
    }

    public static function ok(string $path, int $sizeBytes, array $preClearRowCounts): self
    {
        return new self(true, null, $path, $sizeBytes, $preClearRowCounts);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason, null, null, []);
    }
}
