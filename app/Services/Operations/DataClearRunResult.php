<?php

namespace App\Services\Operations;

/** Outcome of DataClearService::run() — never throws for an expected refusal (bad environment, bad phrase, backup failure, ...), only for a genuine bug. */
final class DataClearRunResult
{
    private function __construct(
        public readonly bool $refused,
        public readonly ?string $reason,
        public readonly ?string $operationId,
        /** @var array<string,int> table => rows deleted */
        public readonly array $rowCounts,
        public readonly ?string $backupPath,
    ) {
    }

    /**
     * $operationId is nullable and usually omitted -- most refusals (bad
     * environment, bad selection, wrong phrase) happen before an
     * operation id is even generated. Pass one only when a real audit-log
     * entry already exists for this refusal (currently: a backup
     * verification failure, which happens after DataClearAuditLogger::
     * recordAttempt() has already run) so a caller can correlate the
     * refusal with that exact log entry.
     */
    public static function refused(string $reason, ?string $operationId = null): self
    {
        return new self(true, $reason, $operationId, [], null);
    }

    public static function success(string $operationId, array $rowCounts, string $backupPath): self
    {
        return new self(false, null, $operationId, $rowCounts, $backupPath);
    }
}
