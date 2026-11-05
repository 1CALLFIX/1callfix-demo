package com.onecallfix.shell;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Context;
import android.media.AudioAttributes;
import android.net.Uri;
import android.os.Build;

/** Two notification channels: loud ringing job/delivery offers, and everything else. */
final class PushChannels {

    static final String OFFERS = "offers";
    static final String GENERAL = "general";

    private PushChannels() {
    }

    static void create(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager nm = context.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }

        NotificationChannel offers = new NotificationChannel(OFFERS, "New job and delivery offers", NotificationManager.IMPORTANCE_HIGH);
        offers.setDescription("Rings loudly when a new job or delivery is offered to you.");
        offers.enableVibration(true);
        offers.setVibrationPattern(new long[]{0, 800, 400, 800, 400, 800});
        offers.setSound(Uri.parse("android.resource://" + context.getPackageName() + "/" + R.raw.offer_ring),
                new AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build());
        offers.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        nm.createNotificationChannel(offers);

        NotificationChannel general = new NotificationChannel(GENERAL, "Order and booking updates", NotificationManager.IMPORTANCE_HIGH);
        general.setDescription("Updates about your bookings, orders and payments.");
        nm.createNotificationChannel(general);
    }
}
