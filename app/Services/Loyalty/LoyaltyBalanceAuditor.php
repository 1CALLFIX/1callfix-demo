<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyPoint;

/**
 * Read-only comparison of the pre-EARN3 balance formula against the FIFO
 * one. Shared by `loyalty:balance-audit` and the Earnings Control
 * monitoring panel, so both always show the same list.
 */
class LoyaltyBalanceAuditor
{
    /** @return array<int, array{user_id: int, role: ?string, old_balance: int, fifo_balance: int, overdrawn: int}> */
    public function findings(): array
    {
        $now = now();
        $out = [];

        $userIds = LoyaltyPoint::query()->distinct()->orderBy('user_id')->pluck('user_id');

        foreach ($userIds->chunk(200) as $chunk) {
            $byUser = LoyaltyPoint::query()->with('user:id,role')->whereIn('user_id', $chunk)->orderBy('id')->get()->groupBy('user_id');

            foreach ($byUser as $userId => $rows) {
                $old = LoyaltyFifoLedger::legacyBalance($rows, $now);
                $fifo = LoyaltyFifoLedger::compute($rows, $now);

                if ($old !== $fifo['balance'] || $old < 0) {
                    $out[] = [
                        'user_id' => (int) $userId,
                        'role' => $rows->first()->user?->role,
                        'old_balance' => $old,
                        'fifo_balance' => $fifo['balance'],
                        'overdrawn' => $fifo['overdrawn'],
                    ];
                }
            }
        }

        return $out;
    }
}
