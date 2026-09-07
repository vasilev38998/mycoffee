package ru.kapouch.evotor;

import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

public class OrderActionReceiver extends BroadcastReceiver {
    private static final String ACTION_ORDER = "ru.kapouch.evotor.ORDER_ACTION";

    static PendingIntent pending(Context context, String orderId, String action, int requestCode) {
        Intent intent = new Intent(context, OrderActionReceiver.class);
        intent.setAction(ACTION_ORDER + "." + action);
        intent.putExtra("order_id", orderId);
        intent.putExtra("order_action", action);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags |= PendingIntent.FLAG_IMMUTABLE;
        return PendingIntent.getBroadcast(context, requestCode, intent, flags);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        final PendingResult pendingResult = goAsync();
        final Context appContext = context.getApplicationContext();
        final String orderId = intent == null ? "" : intent.getStringExtra("order_id");
        final String action = intent == null ? "" : intent.getStringExtra("order_action");
        new Thread(() -> {
            try {
                OrderRecord order = OrderStore.find(appContext, orderId == null ? "" : orderId);
                OrderApi.Result result = OrderApi.perform(appContext, order, action == null ? "" : action);
                if (result.ok && order != null) {
                    OrderRecord updated = OrderStore.updateStatus(appContext, order.orderId, result.status);
                    if (updated != null) {
                        if ("preparing".equals(updated.status)) OrderNotifications.showPreparing(appContext, updated);
                        else OrderNotifications.cancel(appContext, updated.orderId);
                    }
                } else if (order != null) {
                    OrderRecord updated = OrderStore.setError(appContext, order.orderId, result.error);
                    OrderNotifications.showCurrent(appContext, updated);
                }
            } finally {
                pendingResult.finish();
            }
        }, "kapouch-order-action").start();
    }
}
