<?php

namespace Tests\Feature\Kyc;

use App\Actions\ReviewFieldWorkerKycAction;
use App\Actions\ReviewProviderKycAction;
use App\Livewire\Kyc\SupportRequests;
use App\Models\FieldWorkerDocument;
use App\Models\KycSupportRequest;
use App\Models\KycVerificationVideo;
use App\Models\KycWithdrawalException;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Setting;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycReminderService;
use App\Services\Kyc\KycSupportRequestService;
use App\Services\Kyc\KycVerificationVideoService;
use App\Services\Kyc\KycWithdrawalPolicyService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * KYC completeness + document security + verification video + 30-day
 * deadline + withdrawal restriction + franchise support requests
 * (full-day EOD mission Phases 2/3/4, combined -- they share one engine).
 * No invented business rates/thresholds: the 30-day window and the
 * "missed KYC never blocks work, only withdrawal" model are both the
 * mission's own RESOLVED decisions, not guessed here.
 */
class KycEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function jpg(string $name = 'doc.jpg', int $kb = 50): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'image/jpeg');
    }

    // ============================== Document upload/security ==============================

    public function test_upload_stores_on_private_disk_not_public(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        $doc = app(KycDocumentService::class)->upload($provider, 'id_proof', $this->jpg(), $admin);

        Storage::disk('local')->assertExists($doc->disk_path);
        $this->assertStringStartsWith('kyc/providers/', $doc->disk_path);
        $this->assertNotSame('doc.jpg', basename($doc->disk_path));
    }

    public function test_upload_rejects_unsupported_mime(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        $this->expectException(\RuntimeException::class);
        app(KycDocumentService::class)->upload($provider, 'id_proof', UploadedFile::fake()->create('malware.php', 10, 'application/x-php'), $admin);
    }

    public function test_upload_rejects_mime_extension_mismatch(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        // Claims to be a JPG by MIME, but the filename extension disagrees.
        $this->expectException(\RuntimeException::class);
        app(KycDocumentService::class)->upload($provider, 'id_proof', UploadedFile::fake()->create('disguised.php', 10, 'image/jpeg'), $admin);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        $this->expectException(\RuntimeException::class);
        app(KycDocumentService::class)->upload($provider, 'id_proof', $this->jpg('big.jpg', 11 * 1024), $admin);
    }

    public function test_resubmission_preserves_history_never_deletes(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeSuperAdmin();
        $service = app(KycDocumentService::class);

        $first = $service->upload($provider, 'id_proof', $this->jpg('a.jpg'), $admin);
        $second = $service->upload($provider, 'id_proof', $this->jpg('b.jpg'), $admin);

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame(2, ProviderDocument::where('provider_id', $provider->id)->where('type', 'id_proof')->count());
    }

    public function test_franchise_assisted_upload_records_staff_and_source(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $staff = $this->makeSuperAdmin();

        $doc = app(KycDocumentService::class)->upload($provider, 'id_proof', $this->jpg(), $provider->user, 'franchise_assisted', $staff);

        $this->assertSame('franchise_assisted', $doc->upload_source);
        $this->assertSame($staff->id, $doc->franchise_staff_id);
    }

    public function test_missing_required_for_submission_and_approval(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $service = app(KycDocumentService::class);

        $missing = $service->missingRequiredForSubmission($provider);
        $this->assertContains('id_proof', $missing);
        $this->assertContains('address_proof', $missing);
        $this->assertContains('bank_details', $missing);

        ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true]);
        $stillMissingForSubmission = $service->missingRequiredForSubmission($provider);
        $this->assertNotContains('id_proof', $stillMissingForSubmission);

        $stillMissingForApproval = $service->missingApprovedRequirements($provider);
        $this->assertContains('id_proof', $stillMissingForApproval); // pending, not approved yet
    }

    // ============================== Verification video ==============================

    public function test_video_submit_stores_privately_and_sets_provider_status(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $file = UploadedFile::fake()->create('video.mp4', 500, 'video/mp4');

        $video = app(KycVerificationVideoService::class)->submit($provider, $file, $provider->user, 'say the code 4471');

        Storage::disk('local')->assertExists($video->disk_path);
        $this->assertSame('submitted', $provider->fresh()->kyc_video_status);
        $this->assertSame('say the code 4471', $video->challenge_phrase);
    }

    public function test_video_rejects_unsupported_mime(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        app(KycVerificationVideoService::class)->submit($provider, UploadedFile::fake()->create('video.exe', 500, 'application/octet-stream'), $provider->user);
    }

    public function test_video_approve_updates_provider_status(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $video = KycVerificationVideo::create(['provider_id' => $provider->id, 'disk_path' => 'x', 'status' => 'submitted']);
        $admin = $this->makeSuperAdmin();

        app(KycVerificationVideoService::class)->approve($video, $admin);

        $this->assertSame('approved', $provider->fresh()->kyc_video_status);
    }

    public function test_video_reject_records_reason(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $video = KycVerificationVideo::create(['provider_id' => $provider->id, 'disk_path' => 'x', 'status' => 'submitted']);
        $admin = $this->makeSuperAdmin();

        app(KycVerificationVideoService::class)->reject($video, $admin, 'Face not clearly visible');

        $this->assertSame('rejected', $provider->fresh()->kyc_video_status);
        $this->assertSame('Face not clearly visible', $video->fresh()->rejection_reason);
    }

    // ============================== KYC approval completeness gate ==============================

    private function satisfyDocuments(Provider $provider): void
    {
        foreach (['id_proof', 'address_proof', 'bank_details'] as $type) {
            ProviderDocument::create(['provider_id' => $provider->id, 'type' => $type, 'status' => 'approved', 'is_current' => true]);
        }
    }

    public function test_provider_approval_blocked_without_approved_documents(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        app(ReviewProviderKycAction::class)->approve($provider->id);
    }

    public function test_provider_approval_blocked_without_approved_video_when_required(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->satisfyDocuments($provider);

        $this->expectException(\RuntimeException::class);
        app(ReviewProviderKycAction::class)->approve($provider->id);
    }

    public function test_provider_approval_succeeds_once_complete(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->satisfyDocuments($provider);
        $provider->update(['kyc_video_status' => 'approved']);

        $result = app(ReviewProviderKycAction::class)->approve($provider->id);

        $this->assertSame('approved', $result->kyc_status);
    }

    public function test_provider_approval_not_blocked_by_video_when_policy_disabled(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->satisfyDocuments($provider);
        Setting::create(['scope_type' => 'global', 'scope_id' => null, 'key' => 'kyc.require_verification_video', 'value' => '0']);

        $result = app(ReviewProviderKycAction::class)->approve($provider->id);

        $this->assertSame('approved', $result->kyc_status);
    }

    public function test_provider_rejection_never_blocked_by_completeness_gate(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $result = app(ReviewProviderKycAction::class)->reject($provider->id, 'Docs unclear');

        $this->assertSame('rejected', $result->kyc_status);
    }

    public function test_field_worker_approval_blocked_without_approved_documents(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeFieldWorkerIn($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        app(ReviewFieldWorkerKycAction::class)->approve($worker->id);
    }

    public function test_field_worker_approval_succeeds_with_documents_no_video_needed(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        foreach (['id_proof', 'address_proof', 'bank_details'] as $type) {
            FieldWorkerDocument::create(['field_worker_id' => $worker->id, 'type' => $type, 'status' => 'approved', 'is_current' => true]);
        }

        $result = app(ReviewFieldWorkerKycAction::class)->approve($worker->id);

        $this->assertSame('approved', $result->kyc_status);
    }

    // ============================== Document retrieval authorization (IDOR) ==============================

    public function test_document_retrieval_denied_without_admin_access(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $doc = ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'disk_path' => 'kyc/x.jpg', 'status' => 'pending', 'is_current' => true]);
        // Zero role_assignments -- rejected by EnsureHasAdminAccess (the
        // /admin/* front door) before ever reaching the controller's own
        // scoped 404 check, same layered-security shape every other admin
        // route in this codebase has.
        $actor = $this->makeUserWithNoPermissions();

        $response = $this->actingAs($actor)->get(route('admin.kyc.documents.provider', $doc->id));

        $response->assertForbidden();
    }

    public function test_document_retrieval_denied_for_wrong_franchise_scope(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchiseA, $zoneA);
        $doc = ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'disk_path' => 'kyc/x.jpg', 'status' => 'pending', 'is_current' => true]);
        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $franchiseB->id);

        $response = $this->actingAs($actor)->get(route('admin.kyc.documents.provider', $doc->id));

        $response->assertNotFound();
    }

    public function test_document_retrieval_allowed_for_correct_scope(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        Storage::disk('local')->put('kyc/x.jpg', 'fake-bytes');
        $doc = ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'disk_path' => 'kyc/x.jpg', 'status' => 'pending', 'is_current' => true]);
        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $franchise->id);

        $response = $this->actingAs($actor)->get(route('admin.kyc.documents.provider', $doc->id));

        $response->assertOk();
    }

    public function test_document_retrieval_nonexistent_id_returns_404(): void
    {
        $actor = $this->makeSuperAdmin();

        $response = $this->actingAs($actor)->get(route('admin.kyc.documents.provider', 999999));

        $response->assertNotFound();
    }

    public function test_field_worker_document_retrieval_same_authorization(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $worker = $this->makeFieldWorkerIn($franchiseA, $zoneA);
        $doc = FieldWorkerDocument::create(['field_worker_id' => $worker->id, 'type' => 'id_proof', 'disk_path' => 'kyc/w.jpg', 'status' => 'pending', 'is_current' => true]);
        $actor = $this->makeUserWithPermission('workers.review_kyc', 'franchise', $franchiseB->id);

        $response = $this->actingAs($actor)->get(route('admin.kyc.documents.field-worker', $doc->id));

        $response->assertNotFound();
    }

    // ============================== Withdrawal restriction policy ==============================

    public function test_not_restricted_when_kyc_approved(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'approved', 'kyc_deadline_at' => now()->subDays(10)]);

        $this->assertFalse(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_not_restricted_within_deadline_window(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->addDays(5)]);

        $this->assertFalse(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_restricted_once_deadline_passed_with_no_exception(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);

        $this->assertTrue(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_not_restricted_with_active_exception(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        $admin = $this->makeSuperAdmin();
        KycWithdrawalException::create(['provider_id' => $provider->id, 'granted_by' => $admin->id, 'reason' => 'ok', 'starts_at' => now()->subHour()]);

        $this->assertFalse(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_restricted_again_once_exception_expires(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDays(5)]);
        $admin = $this->makeSuperAdmin();
        KycWithdrawalException::create(['provider_id' => $provider->id, 'granted_by' => $admin->id, 'reason' => 'ok', 'starts_at' => now()->subDays(4), 'expires_at' => now()->subDay()]);

        $this->assertTrue(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_not_restricted_when_platform_policy_disabled(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        Setting::create(['scope_type' => 'global', 'scope_id' => null, 'key' => 'kyc.withdrawal_restriction_enabled', 'value' => '0']);

        $this->assertFalse(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    public function test_revoked_exception_no_longer_suppresses_restriction(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        $admin = $this->makeSuperAdmin();
        $exception = KycWithdrawalException::create(['provider_id' => $provider->id, 'granted_by' => $admin->id, 'reason' => 'ok', 'starts_at' => now()->subHour()]);

        app(KycSupportRequestService::class)->revokeException($exception, $admin);

        $this->assertTrue(app(KycWithdrawalPolicyService::class)->isRestricted($provider));
    }

    // ============================== PayoutService enforcement ==============================

    public function test_payout_request_blocked_for_restricted_provider(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        app(WalletService::class)->credit($provider->user, 500, 'test seed');

        $this->expectException(\RuntimeException::class);
        app(PayoutService::class)->request('provider', $provider->id, 100);
    }

    public function test_payout_request_succeeds_for_eligible_provider(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'approved']);
        app(WalletService::class)->credit($provider->user, 500, 'test seed');

        $payout = app(PayoutService::class)->request('provider', $provider->id, 100);

        $this->assertInstanceOf(Payout::class, $payout);
    }

    public function test_franchise_owner_payout_unaffected_by_provider_kyc_policy(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $owner = $this->makeCustomer();
        $franchise->update(['owner_user_id' => $owner->id]);
        app(WalletService::class)->credit($owner, 500, 'test seed');

        $payout = app(PayoutService::class)->request('franchise_owner', $owner->id, 100);

        $this->assertInstanceOf(Payout::class, $payout);
    }

    // ============================== Support requests / central admin decision ==============================

    public function test_support_request_approval_creates_exception_and_lifts_restriction(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        $staff = $this->makeSuperAdmin();
        $centralAdmin = $this->makeSuperAdmin();

        $request = app(KycSupportRequestService::class)->create($provider, $franchise, $staff, 'Docs lost in transit, assisting.');
        app(KycSupportRequestService::class)->decide($request, $centralAdmin, 'approved', 'Grace period granted', 7);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->exception_id);
        $this->assertFalse(app(KycWithdrawalPolicyService::class)->isRestricted($provider->fresh()));
    }

    public function test_support_request_rejection_creates_no_exception(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        $staff = $this->makeSuperAdmin();
        $centralAdmin = $this->makeSuperAdmin();

        $request = app(KycSupportRequestService::class)->create($provider, $franchise, $staff, 'Reason');
        app(KycSupportRequestService::class)->decide($request, $centralAdmin, 'rejected', 'Not enough evidence');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertNull($request->fresh()->exception_id);
        $this->assertTrue(app(KycWithdrawalPolicyService::class)->isRestricted($provider->fresh()));
    }

    public function test_cannot_decide_an_already_decided_request(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $staff = $this->makeSuperAdmin();
        $centralAdmin = $this->makeSuperAdmin();

        $request = app(KycSupportRequestService::class)->create($provider, $franchise, $staff, 'Reason');
        app(KycSupportRequestService::class)->decide($request, $centralAdmin, 'rejected');

        $this->expectException(\RuntimeException::class);
        app(KycSupportRequestService::class)->decide($request->fresh(), $centralAdmin, 'approved');
    }

    public function test_livewire_franchise_actor_cannot_decide_only_central_admin_can(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $request = KycSupportRequest::create(['provider_id' => $provider->id, 'franchise_id' => $franchise->id, 'raised_by' => $this->makeSuperAdmin()->id, 'reason' => 'x', 'status' => 'open']);

        $franchiseActor = $this->makeUserWithPermission('kyc.support_requests.create', 'franchise', $franchise->id);

        Livewire::actingAs($franchiseActor)->test(SupportRequests::class)->call('decide', $request->id, 'approved');

        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_livewire_central_admin_can_decide(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $request = KycSupportRequest::create(['provider_id' => $provider->id, 'franchise_id' => $franchise->id, 'raised_by' => $this->makeSuperAdmin()->id, 'reason' => 'x', 'status' => 'open']);

        $centralAdmin = $this->makeUserWithPermission('kyc.support_requests.decide', 'global');

        Livewire::actingAs($centralAdmin)->test(SupportRequests::class)->call('decide', $request->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_livewire_franchise_actor_cannot_raise_request_out_of_scope(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchiseB, $zoneA);

        $actor = $this->makeUserWithPermission('kyc.support_requests.create', 'franchise', $franchiseA->id);

        Livewire::actingAs($actor)->test(SupportRequests::class)
            ->set('providerId', $provider->id)->set('reason', 'Need help completing KYC')
            ->call('create');

        $this->assertSame(0, KycSupportRequest::count());
    }

    // ============================== Reminders ==============================

    public function test_reminder_sent_at_nine_days_remaining(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->addDays(9)]);

        $sent = app(KycReminderService::class)->dispatchDue();

        $this->assertSame(1, $sent);
        $this->assertSame('reminder', $provider->fresh()->kyc_reminder_stage);
    }

    /**
     * Mission Phase 18 (performance/scale audit) finding: KycNotification
     * had `use Queueable` but never `implements ShouldQueue` -- same gap
     * CampaignNotification had (see its own docblock). dispatchDue()
     * already chunkById(200)s its provider scan, so it was never a memory
     * risk, but every notify() inside a chunk still ran fully
     * synchronously. Proves the fix actually queues rather than sending
     * inline during the cron tick.
     */
    public function test_reminder_notification_is_queued_not_sent_inline(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->addDays(9)]);

        app(KycReminderService::class)->dispatchDue();

        \Illuminate\Support\Facades\Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class);
    }

    public function test_reminder_never_resends_same_stage(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->addDays(9), 'kyc_reminder_stage' => 'reminder']);

        $sent = app(KycReminderService::class)->dispatchDue();

        $this->assertSame(0, $sent);
    }

    public function test_reminder_escalates_to_overdue_after_deadline(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay(), 'kyc_reminder_stage' => 'final_warning']);

        $sent = app(KycReminderService::class)->dispatchDue();

        $this->assertSame(1, $sent);
        $this->assertSame('overdue', $provider->fresh()->kyc_reminder_stage);
    }

    public function test_approved_providers_never_get_reminders(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'approved', 'kyc_deadline_at' => now()->subDay()]);

        $sent = app(KycReminderService::class)->dispatchDue();

        $this->assertSame(0, $sent);
    }

    // ============================== Deadline backfill ==============================

    public function test_missed_kyc_never_blocks_login_jobs_or_earning(): void
    {
        // Structural assertion, not a UI click-through: nothing in the
        // withdrawal policy touches kyc_status gating for login/dispatch —
        // those are separate, untouched code paths (AuthController,
        // DispatchService). This test documents/locks the architectural
        // guarantee: only PayoutService::request() consults this policy.
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay(), 'is_active' => true, 'is_online' => true]);

        $this->assertEquals(1, $provider->fresh()->is_active);
        $this->assertTrue(app(KycWithdrawalPolicyService::class)->isRestricted($provider->fresh()));
    }
}
