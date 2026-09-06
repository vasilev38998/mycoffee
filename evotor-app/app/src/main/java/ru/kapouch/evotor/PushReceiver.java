package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Bundle;

import ru.evotor.pushNotifications.PushNotificationReceiver;

public class PushReceiver extends PushNotificationReceiver {
    @Override
    public void onReceivePushNotification(Context context, Bundle data, long messageId) {
        String type = value(data, "type", "");
        if (!"new_order".equals(type) && !"test".equals(type)) return;

        String title = value(data, "title", "Kapouch · новый заказ");
        String description = normalizeDescription(value(data, "description", "Откройте Kapouch для просмотра заказа."));
        String orderId = value(data, "order_id", "");

        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        prefs.edit()
                .putString(MainActivity.KEY_LAST_TITLE, title)
                .putString(MainActivity.KEY_LAST_DESCRIPTION, description)
                .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                .apply();

        if ("new_order".equals(type) && !orderId.isEmpty()) {
            OrderRecord order = OrderStore.upsertNew(
                    context,
                    orderId,
                    value(data, "order_number", orderId),
                    title,
                    description,
                    value(data, "action_url", ""),
                    value(data, "action_token", ""),
                    System.currentTimeMillis()
            );
            if (prefs.getBoolean(MainActivity.KEY_ENABLED, true)) {
                OrderNotifications.showNewOrder(context, order, false);
                OrderNotifications.scheduleReminder(context, order.orderId);
            }
            return;
        }

        if (prefs.getBoolean(MainActivity.KEY_ENABLED, true)) {
            OrderNotifications.showTest(context, title, description, testNotificationId(messageId));
        }
    }

    static String normalizeDescription(String value) {
        if (value == null || value.isEmpty()) return value;
        String normalized = value.replace("\\u" + "20bd", "₽");
        return normalized.replaceAll("(?i)(\\d)\\s+20bd\\b", "$1 ₽");
    }

    private static String value(Bundle data, String key, String fallback) {
        Object raw = data.get(key);
        if (raw == null) return fallback;
        String value = String.valueOf(raw).trim();
        return value.isEmpty() ? fallback : value;
    }

    private static int testNotificationId(long messageId) {
        if (messageId > 0) return (int) (messageId ^ (messageId >>> 32));
        return (int) System.currentTimeMillis();
    }
}
