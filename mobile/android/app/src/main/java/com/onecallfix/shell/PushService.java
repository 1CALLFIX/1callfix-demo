package com.onecallfix.shell;

import android.app.PendingIntent;
import android.content.Intent;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;

import com.capacitorjs.plugins.pushnotifications.MessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * The server sends data-only pushes (title, body, link, kind): the same message the website's service worker
 * shows. Android never shows a data message by itself, so this service shows it, also when the app is closed.
 * kind=offer uses the loud ringing channel. The parent class still hands the message to the page when it is open.
 */
public class PushService extends MessagingService {

    static final String EXTRA_LINK = "onecallfix_link";

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        super.onMessageReceived(message);

        Map<String, String> data = message.getData();
        String title = data.get("title");
        if (title == null || title.isEmpty() || message.getNotification() != null) {
            return;
        }
        boolean offer = "offer".equals(data.get("kind"));
        if (offer && MainActivity.inForeground) {
            return; // the open page is already ringing for it
        }

        PushChannels.create(this);

        Intent open = new Intent(this, MainActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_LINK, data.get("link"));
        int id = (int) (System.currentTimeMillis() & 0x7fffffff);
        PendingIntent tap = PendingIntent.getActivity(this, id, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder b = new NotificationCompat.Builder(this, offer ? PushChannels.OFFERS : PushChannels.GENERAL)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle(title)
                .setContentText(data.get("body"))
                .setStyle(new NotificationCompat.BigTextStyle().bigText(data.get("body")))
                .setAutoCancel(true)
                .setContentIntent(tap)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setCategory(offer ? NotificationCompat.CATEGORY_CALL : NotificationCompat.CATEGORY_MESSAGE);
        if (offer) {
            b.setTimeoutAfter(120_000); // an offer older than this has gone to someone else
        }

        try {
            NotificationManagerCompat.from(this).notify(id, b.build());
        } catch (SecurityException e) {
            // notification permission not granted; nothing to show
        }
    }
}
