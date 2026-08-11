<?php

namespace App\Notifications\Support;

use App\Models\Setting;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;

/**
 * One place that turns the admin-configurable `notifications.channels`
 * Setting (comma list: mail,sms,push) into the channel classes Laravel's
 * Notification::via() expects — reuses Setting::get()'s existing scope
 * cascade, called once per dispatch site rather than duplicated in every
 * Notification::via() method.
 */
class ChannelResolver
{
    public static function resolve(array $scope = []): array
    {
        $configured = Setting::get('notifications.channels', 'mail', $scope);

        $map = ['mail' => 'mail', 'sms' => SmsChannel::class, 'push' => PushChannel::class];

        return collect(explode(',', $configured))
            ->map(fn ($c) => trim($c))
            ->filter(fn ($c) => isset($map[$c]))
            ->map(fn ($c) => $map[$c])
            ->values()
            ->all();
    }
}
