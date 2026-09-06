package ru.kapouch.evotor;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.SystemClock;

final class OrderNotifications {
    static final String CHANNEL_ID = "kapouch_new_orders_v4";
    private static final long[] VIBRATION = new long[]{0, 350, 140, 350, 140, 650};
    private static final long REMINDER_DELAY_MS = 45_000L;
    private static final int MAX_REMINDERS = 3;

    private OrderNotifications() {}

    static void showNewOrder(Context context, OrderRecord order, boolean repeat) {
        NotificationManager manager = manager(context);
        if (manager == null || order == null) return;
        ensureChannel(manager);

        PendingIntent open = openPending(context, order);
        PendingIntent accept = OrderActionReceiver.pending(context, order.orderId, "accept", requestCode(order.orderId, 11));
        String title = repeat ? "ЗАКАЗ ЖДЁТ ПРИНЯТИЯ · " + order.orderNumber : "НОВЫЙ ЗАКАЗ · " + order.orderNumber;
        String text = withError(order.description, order.lastError);

        Notification.Builder builder = builder(context)
                .setSmallIcon(android.R.drawable.ic_dialog_alert)
                .setContentTitle(title)
                .setContentText(text)
                .setStyle(new Notification.BigTextStyle().bigText(text))
                .setContentIntent(open)
                .setOngoing(true)
                .setAutoCancel(false)
                .setWhen(System.currentTimeMillis())
                .setShowWhen(true)
                .setPriority(Notification.PRIORITY_MAX)
                .setCategory(Notification.CATEGORY_ALARM)
                .setVisibility(Notification.VISIBILITY_PUBLIC)
                .setColor(Color.rgb(245, 185, 63))
                .setTicker("НОВЫЙ ЗАКАЗ KAPOUCH")
                .setOnlyAlertOnce(false)
                .setVibrate(VIBRATION)
                .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM));
        builder.addAction(android.R.drawable.ic_menu_send, "ПРИНЯТЬ", accept);
        builder.addAction(android.R.drawable.ic_menu_view, "ОТКРЫТЬ", open);
        manager.notify(notificationId(order.orderId), builder.build());

        // Some Evotor Android builds suppress the sound attached to Notification.Builder.
        // Play a short alarm-stream sequence explicitly as a compatibility fallback.
        BaristaAlertPlayer.playNewOrder(context.getApplicationContext());
    }

    static void showPreparing(Context context, OrderRecord order) {
        NotificationManager manager = manager(context);
        if (manager == null || order == null) return;
        ensureChannel(manager);
        cancelReminder(context, order.orderId);

        PendingIntent open = openPending(context, order);
        PendingIntent ready = OrderActionReceiver.pending(context, order.orderId, "ready", requestCode(order.orderId, 12));
        String text = withError(order.description, order.lastError);
        Notification.Builder builder = builder(context)
                .setSmallIcon(android.R.drawable.ic_menu_recent_history)
                .setContentTitle("ЗАКАЗ ПРИНЯТ · " + order.orderNumber)
                .setContentText(text)
                .setStyle(new Notification.BigTextStyle().bigText(text))
                .setContentIntent(open)
                .setOngoing(true)
                .setAutoCancel(false)
                .setWhen(System.currentTimeMillis())
                .setPriority(Notification.PRIORITY_HIGH)
                .setCategory(Notification.CATEGORY_MESSAGE)
                .setVisibility(Notification.VISIBILITY_PUBLIC)
                .setColor(Color.rgb(62, 146, 92))
                .setOnlyAlertOnce(true)
                .setSound(null)
                .setVibrate(new long[]{0});
        builder.addAction(android.R.drawable.ic_menu_save, "ГОТОВ", ready);
        builder.addAction(android.R.drawable.ic_menu_view, "ОТКРЫТЬ", open);
        manager.notify(notificationId(order.orderId), builder.build());
    }

    static void showCurrent(Context context, OrderRecord order) {
        if (order == null) return;
        if ("new".equals(order.status)) showNewOrder(context, order, false);
        else if ("preparing".equals(order.status)) showPreparing(context, order);
        else cancel(context, order.orderId);
    }

    static void showTest(Context context, String title, String description, int id) {
        NotificationManager manager = manager(context);
        if (manager == null) return;
        ensureChannel(manager);
        Intent openIntent = new Intent(context, MainActivity.class);
        openIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent open = PendingIntent.getActivity(context, id, openIntent, pendingFlags());
        Notification.Builder builder = builder(context)
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentTitle(title)
                .setContentText(description)
                .setStyle(new Notification.BigTextStyle().bigText(description))
                .setContentIntent(open)
                .setAutoCancel(true)
                .setPriority(Notification.PRIORITY_HIGH)
                .setVisibility(Notification.VISIBILITY_PUBLIC)
                .setColor(Color.rgb(245, 185, 63))
                .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION))
                .setVibrate(new long[]{0, 250, 120, 250});
        manager.notify(id, builder.build());
        BaristaAlertPlayer.playTest(context.getApplicationContext());
    }

    static void scheduleReminder(Context context, String orderId) {
        AlarmManager alarm = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        if (alarm == null || orderId == null || orderId.isEmpty()) return;
        PendingIntent pending = reminderPending(context, orderId);
        long at = SystemClock.elapsedRealtime() + REMINDER_DELAY_MS;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            alarm.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending);
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
            alarm.setExact(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending);
        } else {
            alarm.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pending);
        }
    }

    static boolean shouldRepeat(OrderRecord order) {
        return order != null && "new".equals(order.status) && order.reminderCount < MAX_REMINDERS;
    }

    static void cancelReminder(Context context, String orderId) {
        AlarmManager alarm = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        if (alarm != null) alarm.cancel(reminderPending(context, orderId));
    }

    static void cancel(Context context, String orderId) {
        cancelReminder(context, orderId);
        NotificationManager manager = manager(context);
        if (manager != null) manager.cancel(notificationId(orderId));
    }

    private static Notification.Builder builder(Context context) {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(context, CHANNEL_ID)
                : new Notification.Builder(context);
    }

    private static void ensureChannel(NotificationManager manager) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "Новые заказы Kapouch", NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Громкие уведомления и действия по новым PWA-заказам");
        channel.enableVibration(true);
        channel.setVibrationPattern(VIBRATION);
        channel.enableLights(true);
        channel.setLightColor(Color.rgb(245, 185, 63));
        channel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        Uri sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM);
        AudioAttributes attributes = new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build();
        channel.setSound(sound, attributes);
        manager.createNotificationChannel(channel);
    }

    private static PendingIntent openPending(Context context, OrderRecord order) {
        Intent open = new Intent(context, MainActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        open.putExtra("order_id", order.orderId);
        return PendingIntent.getActivity(context, requestCode(order.orderId, 10), open, pendingFlags());
    }

    private static PendingIntent reminderPending(Context context, String orderId) {
        Intent intent = new Intent(context, OrderReminderReceiver.class);
        intent.setAction("ru.kapouch.evotor.REMIND_ORDER");
        intent.putExtra("order_id", orderId);
        return PendingIntent.getBroadcast(context, requestCode(orderId, 13), intent, pendingFlags());
    }

    private static int notificationId(String orderId) {
        return requestCode(orderId, 1);
    }

    private static int requestCode(String orderId, int salt) {
        int base;
        try {
            base = Integer.parseInt(orderId);
        } catch (Exception e) {
            base = orderId == null ? 0 : orderId.hashCode();
        }
        return (base * 31) ^ (salt * 1009);
    }

    private static int pendingFlags() {
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags |= PendingIntent.FLAG_IMMUTABLE;
        return flags;
    }

    private static String withError(String description, String error) {
        String text = description == null ? "" : description;
        if (error != null && !error.isEmpty()) text += "\n⚠ " + error;
        return text;
    }

    private static NotificationManager manager(Context context) {
        return (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
    }
}
