package ru.kapouch.evotor;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

public class OrderReminderReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        String orderId = intent == null ? "" : intent.getStringExtra("order_id");
        if (orderId == null || orderId.isEmpty()) return;
        OrderRecord order = OrderStore.find(context, orderId);
        if (!OrderNotifications.shouldRepeat(order)) return;
        order = OrderStore.incrementReminder(context, orderId);
        if (order == null || !"new".equals(order.status)) return;
        OrderNotifications.showNewOrder(context, order, true);
        if (OrderNotifications.shouldRepeat(order)) OrderNotifications.scheduleReminder(context, order.orderId);
    }
}
