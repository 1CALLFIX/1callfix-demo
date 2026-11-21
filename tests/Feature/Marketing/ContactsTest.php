<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\Contacts;
use App\Models\MarketingContact;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\ContactAppInvite;
use App\Services\Marketing\ContactImporter;
use App\Services\Marketing\ContactInviter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Digital Marketing, contacts (owner 2026-11-21): import old customers, invite by email / WhatsApp, see who joined. */
class ContactsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = storage_path('framework/testing/contacts-'.Str::random(6).'.csv');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $body);

        return $path;
    }

    public function test_the_invite_template_is_in_notification_center(): void
    {
        $t = NotificationTemplate::where('key', 'app_invite')->sole();
        $this->assertStringContainsString('play.google.com', $t->body_template);
        $this->assertStringContainsString('యాప్', $t->body_template);
        $this->assertSame($t->title_template, ContactInviter::message()['title']);
    }

    public function test_preview_reads_headings_in_any_order_normalises_numbers_and_says_what_is_wrong(): void
    {
        $path = $this->csv("Mobile,Customer Name,City,Email\n98765 43210,Ravi Kumar,Nellore,RAVI@example.com\n+91-9876543210,Ravi again,,\n12345,Bad Number,,\n,No contact,,\n0 9123456789,Sita,Guntur,not-an-email\n");

        $p = app(ContactImporter::class)->preview($path);

        $this->assertSame([['name' => 'Ravi Kumar', 'phone' => '+919876543210', 'email' => 'ravi@example.com', 'city' => 'Nellore', 'line' => 2],
            ['name' => 'Sita', 'phone' => '+919123456789', 'email' => null, 'city' => 'Guntur', 'line' => 6]], $p['rows']);
        $this->assertSame(1, $p['duplicates']);
        $this->assertSame(['Row 4: no valid mobile number or email for Bad Number.', 'Row 5: no valid mobile number or email for No contact.'], $p['problems']);
    }

    public function test_a_file_without_headings_is_read_as_name_mobile_email_city(): void
    {
        $p = app(ContactImporter::class)->preview($this->csv("Lakshmi,9000000001,lak@example.com,Nellore\n"));

        $this->assertSame('+919000000001', $p['rows'][0]['phone']);
        $this->assertSame('Lakshmi', $p['rows'][0]['name']);
    }

    public function test_import_adds_new_fills_blanks_of_existing_matches_cities_and_marks_customers_already_on_the_app(): void
    {
        [, $city, $franchise] = $this->makeFranchiseTree();
        $city->forceFill(['name' => 'Nellore'])->save();
        MarketingContact::create(['name' => 'Old Ravi', 'phone' => '+919876543210']);
        User::create(['uuid' => (string) Str::uuid(), 'name' => 'Sita', 'phone' => '9123456789', 'role' => 'customer', 'status' => 'active']);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Contacts::class)
            ->set('file', UploadedFile::fake()->createWithContent('list.csv', "Name,Mobile,Email,City\nRavi Kumar,9876543210,ravi@example.com,nellore\nSita,9123456789,,Nellore\n"))
            ->assertSee('2 contacts ready to add')
            ->call('import')
            ->assertSee('1 contacts added, 1 updated, 1 are already on the app');

        $ravi = MarketingContact::where('phone', '+919876543210')->sole();
        $this->assertSame(['Old Ravi', 'ravi@example.com', $city->id, $franchise->id], [$ravi->name, $ravi->email, $ravi->city_id, $ravi->franchise_id]);
        $this->assertNotNull(MarketingContact::where('phone', '+919123456789')->value('joined_user_id'));
    }

    public function test_email_invites_go_once_never_to_unsubscribed_joined_or_email_less_contacts(): void
    {
        Notification::fake();
        $ok = MarketingContact::create(['name' => 'Ravi', 'phone' => '+919000000001', 'email' => 'ravi@example.com']);
        MarketingContact::create(['name' => 'No email', 'phone' => '+919000000002']);
        MarketingContact::create(['name' => 'Gone', 'phone' => '+919000000003', 'email' => 'gone@example.com', 'unsubscribed_at' => now()]);
        MarketingContact::create(['name' => 'Joined', 'phone' => '+919000000004', 'email' => 'joined@example.com', 'joined_user_id' => 1]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Contacts::class)->assertSee('Email 1 contact')->call('emailAll')->assertSee('emailed to 1 contacts')
            ->call('emailAll')->assertSee('Nobody left to email');

        Notification::assertSentOnDemandTimes(ContactAppInvite::class, 1);
        Notification::assertSentOnDemand(ContactAppInvite::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ravi@example.com' && $n->contactId === $ok->id);
        $this->assertNotNull($ok->fresh()->email_invited_at);
    }

    public function test_the_email_has_the_invitation_and_a_working_unsubscribe_that_needs_a_button_press(): void
    {
        $contact = MarketingContact::create(['name' => 'Ravi Kumar', 'phone' => '+919000000001', 'email' => 'ravi@example.com']);
        $mail = (new ContactAppInvite($contact->id))->toMail(new AnonymousNotifiable);
        $this->assertSame('Hi Ravi,', $mail->greeting);
        $this->assertStringContainsString(route('marketing.unsubscribe', $contact->unsubscribe_token), implode(' ', $mail->outroLines));

        $this->get(route('marketing.unsubscribe', $contact->unsubscribe_token))->assertOk()->assertSee('Stop the emails');
        $this->assertNull($contact->fresh()->unsubscribed_at, 'opening the link alone changes nothing');
        $this->post(route('marketing.unsubscribe.store', $contact->unsubscribe_token))->assertOk()->assertSee('You will not get these emails again');
        $this->assertNotNull($contact->fresh()->unsubscribed_at);
        $this->assertSame([], (new ContactAppInvite($contact->id))->via(new AnonymousNotifiable), 'a queued invite is dropped after unsubscribing');
        $this->get('/unsubscribe/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_whatsapp_opens_with_the_invitation_and_records_it(): void
    {
        $contact = MarketingContact::create(['name' => 'Ravi Kumar', 'phone' => '+919000000001']);
        $link = ContactInviter::whatsappLink($contact);
        $this->assertStringStartsWith('https://wa.me/919000000001?text=Hi%20Ravi', $link);
        $this->assertStringContainsString(rawurlencode('play.google.com'), $link);

        Livewire::actingAs($this->makeSuperAdmin())->test(Contacts::class)->assertSee('Send on WhatsApp')->call('markWhatsapp', $contact->id);
        $this->assertNotNull($contact->fresh()->whatsapp_invited_at);
    }

    public function test_permission_and_a_city_team_sees_and_imports_only_its_own_city(): void
    {
        [, $city, $franchise] = $this->makeFranchiseTree();
        $other = $this->makeCity();
        MarketingContact::create(['name' => 'Mine', 'phone' => '+919000000001', 'city_id' => $city->id, 'country_id' => $city->country_id, 'franchise_id' => $franchise->id]);
        MarketingContact::create(['name' => 'Theirs', 'phone' => '+919000000002', 'city_id' => $other->id, 'country_id' => $other->country_id]);

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Contacts::class)->assertForbidden();
        $team = $this->makeUserWithPermission('marketing.contacts.manage', 'city', $city->id);
        Livewire::actingAs($team)->test(Contacts::class)->assertSee('Mine')->assertDontSee('Theirs')
            ->set('file', UploadedFile::fake()->createWithContent('list.csv', "Name,Mobile,City\nNew One,9000000003,{$other->name}\n"))
            ->call('import');

        $this->assertSame($city->id, MarketingContact::where('phone', '+919000000003')->value('city_id'), 'a city team imports into its own city');
    }
}
