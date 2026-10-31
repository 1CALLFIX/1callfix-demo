<?php

namespace App\Services\Parcel;

use App\Models\ParcelOrder;
use App\Models\ParcelOrderMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Parcel Stage 2 — chat between a parcel's sender and its assigned rider. The only two participants are derived
 * from the order itself (customer_id, assigned rider's user), never from the client. Messages can be sent only while
 * a rider is on the job (assigned → handed over / returned); the history stays readable to both afterwards.
 */
class ParcelChatService
{
    public const MAX_LENGTH = 500;

    /** @return list<int> */
    public function participantIds(ParcelOrder $order): array
    {
        $order->loadMissing('assignedWorker');

        return array_values(array_filter([$order->customer_id, $order->assignedWorker?->user_id]));
    }

    public function isParticipant(ParcelOrder $order, int $userId): bool
    {
        return in_array($userId, $this->participantIds($order), true);
    }

    public function isOpen(ParcelOrder $order): bool
    {
        return $order->assigned_worker_id !== null && in_array($order->status, ParcelOrder::ACTIVE_RIDER_STATUSES, true);
    }

    public function send(ParcelOrder $order, User $sender, string $body): ParcelOrderMessage
    {
        if (! $this->isParticipant($order, $sender->id)) {
            throw new \RuntimeException('You are not part of this parcel.');
        }
        if (! $this->isOpen($order)) {
            throw new \RuntimeException('Chat is open only while a rider is on this parcel.');
        }
        $body = trim(preg_replace('/\s+/u', ' ', $body));
        if ($body === '') {
            throw new \RuntimeException('Type a message.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new \RuntimeException('Keep messages under '.self::MAX_LENGTH.' characters.');
        }

        return ParcelOrderMessage::create(['parcel_order_id' => $order->id, 'sender_id' => $sender->id, 'body' => $body]);
    }

    /** @return Collection<int, ParcelOrderMessage> the conversation, oldest first; marks the other side's messages read. */
    public function thread(ParcelOrder $order, User $reader): Collection
    {
        if (! $this->isParticipant($order, $reader->id)) {
            throw new \RuntimeException('You are not part of this parcel.');
        }
        ParcelOrderMessage::where('parcel_order_id', $order->id)->where('sender_id', '!=', $reader->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ParcelOrderMessage::where('parcel_order_id', $order->id)->orderBy('id')->limit(200)->get();
    }

    public function unreadFor(ParcelOrder $order, int $userId): int
    {
        return ParcelOrderMessage::where('parcel_order_id', $order->id)->where('sender_id', '!=', $userId)->whereNull('read_at')->count();
    }
}
