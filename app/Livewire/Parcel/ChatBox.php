<?php

namespace App\Livewire\Parcel;

use App\Models\ParcelOrder;
use App\Services\Parcel\ParcelChatService;
use Livewire\Component;

/**
 * Parcel Stage 2 — the chat box shown on the sender's tracking page and in the rider's current job. The signed-in
 * user must be one of the order's two participants (re-checked on every call); polls while the chat is open.
 */
class ChatBox extends Component
{
    public int $orderId;

    public string $body = '';

    public string $error = '';

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        abort_unless(app(ParcelChatService::class)->isParticipant($this->order(), (int) auth()->id()), 404);
    }

    private function order(): ParcelOrder
    {
        return ParcelOrder::with('assignedWorker')->findOrFail($this->orderId);
    }

    public function send(ParcelChatService $chat): void
    {
        $this->error = '';
        try {
            $chat->send($this->order(), auth()->user(), $this->body);
            $this->body = '';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(ParcelChatService $chat)
    {
        $order = $this->order();
        abort_unless($chat->isParticipant($order, (int) auth()->id()), 404);

        return view('livewire.parcel.chat-box', [
            'messages' => $chat->thread($order, auth()->user()),
            'open' => $chat->isOpen($order),
            'me' => (int) auth()->id(),
            'otherName' => $order->customer_id === auth()->id() ? 'Rider' : 'Sender',
        ]);
    }
}
