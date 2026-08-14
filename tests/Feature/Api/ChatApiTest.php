<?php

namespace Tests\Feature\Api;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Universal Chat (mission Phase 6) — chat_messages existed but was
 * completely dormant (no service/controller/authorization/routes,
 * confirmed by direct inspection). Every test here exercises the real HTTP
 * surface, not just ChatService directly — IDOR prevention in particular
 * needs to be proven at the boundary an attacker would actually hit.
 */
class ChatApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_unauthenticated_request_rejected(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();

        $this->postJson("/api/bookings/{$booking->id}/chat", ['receiver_id' => 1, 'message' => 'hi'])->assertUnauthorized();
    }

    public function test_customer_can_message_provider(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $response = $this->actingAs($customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id, 'message' => 'On my way?',
        ]);

        $response->assertCreated();
        $this->assertSame(1, ChatMessage::where('booking_id', $booking->id)->count());
    }

    public function test_provider_can_reply_to_customer(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $response = $this->actingAs($provider->user, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $customer->id, 'message' => '5 minutes away.',
        ]);

        $response->assertCreated();
    }

    public function test_worker_and_provider_can_message_each_other_when_assigned(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $worker = $this->makeFieldWorkerIn($scenario['franchise'], $scenario['zone']);
        $scenario['booking']->update(['assigned_worker_id' => $worker->id]);

        $response = $this->actingAs($scenario['provider']->user, 'sanctum')->postJson("/api/bookings/{$scenario['booking']->id}/chat", [
            'receiver_id' => $worker->user_id, 'message' => 'Handle this one.',
        ]);

        $response->assertCreated();
    }

    public function test_customer_can_message_assigned_worker(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $worker = $this->makeFieldWorkerIn($scenario['franchise'], $scenario['zone']);
        $scenario['booking']->update(['assigned_worker_id' => $worker->id]);

        $response = $this->actingAs($scenario['customer'], 'sanctum')->postJson("/api/bookings/{$scenario['booking']->id}/chat", [
            'receiver_id' => $worker->user_id, 'message' => 'Please ring the bell.',
        ]);

        $response->assertCreated();
    }

    public function test_non_participant_cannot_send(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $stranger = $this->makeCustomer();

        $response = $this->actingAs($stranger, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id, 'message' => 'hi',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_cannot_message_a_non_participant_receiver(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeAssignedBookingScenario();
        $stranger = $this->makeCustomer();

        // IDOR: even though the sender IS a real participant, the
        // receiver_id must ALSO be a genuine participant of THIS booking.
        $response = $this->actingAs($customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $stranger->id, 'message' => 'hi',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_customer_cannot_read_another_bookings_conversation(): void
    {
        ['booking' => $bookingA, 'customer' => $customerA, 'provider' => $providerA] = $this->makeAssignedBookingScenario();
        ['booking' => $bookingB, 'customer' => $customerB, 'provider' => $providerB] = $this->makeAssignedBookingScenario();

        ChatMessage::create(['booking_id' => $bookingB->id, 'sender_id' => $customerB->id, 'receiver_id' => $providerB->user_id, 'message' => 'private']);

        // customerA tries to read bookingB's thread by guessing the booking ID and the other party's user ID.
        $response = $this->actingAs($customerA, 'sanctum')->getJson("/api/bookings/{$bookingB->id}/chat/{$providerB->user_id}");

        $response->assertForbidden();
    }

    public function test_participant_can_read_own_conversation_and_it_marks_read(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $msg = ChatMessage::create(['booking_id' => $booking->id, 'sender_id' => $provider->user_id, 'receiver_id' => $customer->id, 'message' => 'hello']);

        $response = $this->actingAs($customer, 'sanctum')->getJson("/api/bookings/{$booking->id}/chat/{$provider->user_id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('messages'));
        $this->assertNotNull($msg->fresh()->read_at);
    }

    public function test_cannot_read_conversation_with_a_non_participant(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeAssignedBookingScenario();
        $stranger = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')->getJson("/api/bookings/{$booking->id}/chat/{$stranger->id}");

        $response->assertForbidden();
    }

    public function test_attachment_upload_and_authorized_retrieval(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

        $send = $this->actingAs($customer, 'sanctum')->post("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id, 'attachment' => $file,
        ]);
        $send->assertCreated();
        $messageId = $send->json('id');

        $retrieve = $this->actingAs($provider->user, 'sanctum')->get("/api/chat/attachments/{$messageId}");
        $retrieve->assertOk();
    }

    public function test_attachment_retrieval_denied_to_non_participant(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

        $send = $this->actingAs($customer, 'sanctum')->post("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id, 'attachment' => $file,
        ]);
        $messageId = $send->json('id');
        $stranger = $this->makeCustomer();

        $this->actingAs($stranger, 'sanctum')->get("/api/chat/attachments/{$messageId}")->assertNotFound();
    }

    public function test_unsupported_attachment_type_rejected(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $file = UploadedFile::fake()->create('malware.php', 10, 'application/x-php');

        $response = $this->actingAs($customer, 'sanctum')->post("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id, 'attachment' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_empty_message_without_attachment_rejected(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $response = $this->actingAs($customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $provider->user_id,
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_message_self(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeAssignedBookingScenario();

        $response = $this->actingAs($customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/chat", [
            'receiver_id' => $customer->id, 'message' => 'hi',
        ]);

        $response->assertForbidden();
    }

    public function test_nonexistent_booking_returns_404(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/bookings/999999/chat', ['receiver_id' => 1, 'message' => 'hi'])->assertNotFound();
    }
}
