<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Function check 2026-10-17, F5: the admin KYC document/video and chat
 * attachment links answered a non-number id with a server error (500).
 * They must answer "not found" like any other missing file.
 */
class AdminFileRouteIdTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_admin_file_links_with_a_non_number_id_are_not_found(): void
    {
        $admin = $this->makeSuperAdmin();

        foreach (['kyc/documents/provider', 'kyc/documents/field-worker', 'kyc/videos', 'chat/attachments'] as $path) {
            $this->actingAs($admin)->get(url('/admin/'.$path.'/abc'))->assertNotFound();
        }
    }

    public function test_admin_file_links_with_a_missing_number_id_are_still_not_found(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get(route('admin.kyc.documents.provider', 999999))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.kyc.videos.show', 999999))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.chat.attachments.show', 999999))->assertNotFound();
    }
}
