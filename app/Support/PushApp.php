<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserPushToken;
use Illuminate\Notifications\Notification;

/**
 * Which 1CallFix Android app a push belongs to (owner rule 2026-11-06). A plumbing job rings on the Partner app,
 * a delivery on the Rider app, a booking update on the Customer app, even when one account uses all three on one
 * phone. A notification can say so itself with pushApp(); otherwise the list below decides. null = no app (admin
 * alerts), which goes to the browser token as before.
 */
final class PushApp
{
    public const APPS = ['customer', 'rider', 'partner'];

    /** Notification class (short name) => app. Anything not listed is a customer notification. */
    private const BY_CLASS = [
        'ProviderJobOfferNotification' => 'partner',
        'ProviderJobStatusNotification' => 'partner',
        'ProviderShiftReminderNotification' => 'partner',
        'WorkAssignmentNotification' => 'partner',
        'PayoutStatusNotification' => 'partner',
        'KycNotification' => 'partner',
        'RiderParcelOfferNotification' => 'rider',
        'AdminOpsAlertNotification' => null,
    ];

    public static function for(Notification $notification): ?string
    {
        if (method_exists($notification, 'pushApp')) {
            return $notification->pushApp();
        }
        $short = class_basename($notification);

        return array_key_exists($short, self::BY_CLASS) ? self::BY_CLASS[$short] : 'customer';
    }

    /**
     * The token to push this notification to: that app's own token. If the account has none for that app, its
     * browser token (users.fcm_token), unless that token is really one of its other apps' (then nothing, so an
     * alert never opens in the wrong app).
     */
    public static function tokenFor(User $user, mixed $notification): ?string
    {
        $app = $notification instanceof Notification ? self::for($notification) : null;
        $tokens = UserPushToken::where('user_id', $user->id)->pluck('token', 'app');

        if ($app !== null && ! empty($tokens[$app])) {
            return $tokens[$app];
        }
        if ($user->fcm_token && ! $tokens->contains($user->fcm_token)) {
            return $user->fcm_token;
        }

        return null;
    }

    /** Save this phone's token for the app it came from, and take it off any other account or app. */
    public static function register(User $user, string $app, string $token): void
    {
        UserPushToken::where('token', $token)
            ->where(fn ($q) => $q->where('user_id', '!=', $user->id)->orWhere('app', '!=', $app))
            ->delete();
        User::where('fcm_token', $token)->update(['fcm_token' => null]);
        UserPushToken::updateOrCreate(['user_id' => $user->id, 'app' => $app], ['token' => $token]);
    }

    /** A token FCM said is dead: remove it wherever it is stored. */
    public static function forget(string $token): void
    {
        UserPushToken::where('token', $token)->delete();
        User::where('fcm_token', $token)->update(['fcm_token' => null]);
    }
}
