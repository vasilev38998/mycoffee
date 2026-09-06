package ru.kapouch.evotor;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.os.Build;
import android.os.Bundle;

import ru.evotor.pushNotifications.PushNotificationReceiver;

public class PushReceiver extends PushNotificationReceiver {
    private static final String CHANNEL_ID = "kapouch_new_orders";

    @Override
    public void onReceivePushNotification(Context context, Bundle data, long messageId) {
        String type = value(data, "type", "");
        if (!"new_order".equals(type) && !"test".equals(type)) return;

        String title = value(data, "title", "Kapouch · новый заказ");
        String description = value(data, "description", "Откройте Kapouch для просмотра заказа.");
        String orderId = value(data, "order_id", "");

        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        prefs.edit()
                .putString(MainActivity.KEY_LAST_TITLE, title)
                .putString(MainActivity.KEY_LAST_DESCRIPTION, description)
                .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                .apply();

        if (!prefs.getBoolean(MainActivity.KEY_ENABLED, true)) return;

        NotificationManager manager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager == null) return;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "Новые заказы Kapouch", NotificationManager.IMPORTANCE_HIGH);
            channel.setDescription("Новые заказы из клиентского PWA Kapouch");
            channel.enableVibration(true);
            channel.enableLights(true);
            channel.setLightColor(Color.rgb(245, 185, 63));
            manager.createNotificationChannel(channel);
        }

        Intent open = new Intent(context, MainActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        int pendingFlags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) pendingFlags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent pending = PendingIntent.getActivity(context, notificationId(messageId, orderId), open, pendingFlags);

        Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(context, CHANNEL_ID)
                : new Notification.Builder(context);
        builder.setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentTitle(title)
                .setContentText(description)
                .setStyle(new Notification.BigTextStyle().bigText(description))
                .setContentIntent(pending)
                .setAutoCancel(true)
                .setWhen(System.currentTimeMillis())
                .setDefaults(Notification.DEFAULT_ALL)
                .setPriority(Notification.PRIORITY_MAX)
                .setCategory(Notification.CATEGORY_MESSAGE)
                .setVisibility(Notification.VISIBILITY_PUBLIC);

        manager.notify(notificationId(messageId, orderId), builder.build());
    }

    private static String value(Bundle data, String key, String fallback) {
        Object raw = data.get(key);
        if (raw == null) return fallback;
        String value = String.valueOf(raw).trim();
        return value.isEmpty() ? fallback : value;
    }

    private static int notificationId(long messageId, String orderId) {
        if (messageId > 0) return (int) (messageId ^ (messageId >>> 32));
        return orderId == null || orderId.isEmpty() ? (int) System.currentTimeMillis() : orderId.hashCode();
    }
}
