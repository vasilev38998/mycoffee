package ru.kapouch.evotor;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Clears the active Kapouch customer as soon as the SELL receipt ends. */
public final class ReceiptSessionReceiver extends BroadcastReceiver {
    static final String ACTION_SELL_RECEIPT_CLOSED = "evotor.intent.action.receipt.sell.RECEIPT_CLOSED";
    static final String ACTION_SELL_RECEIPT_CLEARED = "evotor.intent.action.receipt.sell.CLEARED";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (context == null || intent == null) return;
        String action = intent.getAction();
        if (!ACTION_SELL_RECEIPT_CLOSED.equals(action) && !ACTION_SELL_RECEIPT_CLEARED.equals(action)) return;
        CustomerReceiptSession.clear(context.getApplicationContext());
    }
}
