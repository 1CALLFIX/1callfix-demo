<?php

namespace App\Services\Marketing;

use App\Models\MarketingContact;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\ContactAppInvite;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Invites contacts to the app (owner 2026-11-21). The words are the Notification Center template "app_invite" (one
 * place to edit them). Email: queued, once per contact, never to an unsubscribed one, with an unsubscribe link.
 * WhatsApp: a wa.me link per contact, sent by hand from the company phone (bulk WhatsApp needs Meta approval).
 */
class ContactInviter
{
    public const TEMPLATE = 'app_invite';

    /** @return array{title: string, body: string} */
    public static function message(): array
    {
        $t = NotificationTemplate::where('key', self::TEMPLATE)->first();

        return [
            'title' => $t?->title_template ?: 'The new 1CallFix app is here',
            'body' => $t?->body_template ?: 'Download the 1CallFix app: '.\App\Support\AppLinks::android(),
        ];
    }

    /** Contacts an email invite may go to now. */
    public static function emailable(Builder $query): Builder
    {
        return $query->whereNotNull('email')->whereNull('email_invited_at')->whereNull('unsubscribed_at')->whereNull('joined_user_id');
    }

    /** Queues the email invite to every contact in $query that may get one; returns how many. */
    public function emailAll(Builder $query, User $by): int
    {
        $sent = 0;
        self::emailable($query)->orderBy('id')->chunkById(200, function ($contacts) use (&$sent) {
            foreach ($contacts as $contact) {
                Notification::route('mail', $contact->email)->notify(new ContactAppInvite($contact->id));
                $contact->forceFill(['email_invited_at' => now()])->save();
                $sent++;
            }
        });
        if ($sent > 0) {
            ActivityLogger::log($by, 'marketing_contacts', 0, "App invitation emailed to {$sent} contacts", ['count' => $sent]);
        }

        return $sent;
    }

    /** wa.me link with the invitation filled in, for one contact. */
    public static function whatsappLink(MarketingContact $contact): ?string
    {
        if (! $contact->phone) {
            return null;
        }
        $m = self::message();
        $hello = $contact->name ? 'Hi '.strtok($contact->name, ' ').", \n\n" : '';

        return 'https://wa.me/'.ltrim($contact->phone, '+').'?text='.rawurlencode($hello.$m['title']."\n\n".$m['body']);
    }
}
