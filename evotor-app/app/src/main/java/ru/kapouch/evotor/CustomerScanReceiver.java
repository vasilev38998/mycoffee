package ru.kapouch.evotor;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;

import java.text.DecimalFormat;

/**
 * Foreground-only loyalty scanner receiver.
 *
 * This receiver is intentionally NOT declared in AndroidManifest.xml. MainActivity
 * registers it only while the cashier explicitly enables "scan customer card"
 * mode. That keeps Kapouch QR codes out of the normal Evotor product-scan flow
 * and avoids relying on abortBroadcast(), which does not suppress the stock
 * sales UI on the affected physical terminal.
 */
public class CustomerScanReceiver extends BroadcastReceiver {
    static final String ACTION_SCANNED = "ru.evotor.devices.ScannedCode";
    static final String EXTRA_SCANNED_CODE = "ScannedCode";
    static final String KAPOUCH_PREFIX = "KAPOUCH:LOYALTY:";
    static final String KEY_ACTIVE_CUSTOMER_NAME = "active_customer_name";
    static final String KEY_ACTIVE_CUSTOMER_BALANCE = "active_customer_balance";
    static final String KEY_ACTIVE_CUSTOMER_AT = "active_customer_at";

    interface Listener {
        void onCodeAccepted();
        void onNonKapouchCode();
        void onLookupFinished(boolean ok, String message);
    }

    private final Listener listener;
    private boolean busy;

    CustomerScanReceiver(Listener listener) {
        this.listener = listener;
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        if (context == null || intent == null || !ACTION_SCANNED.equals(intent.getAction())) return;
        String code = intent.getStringExtra(EXTRA_SCANNED_CODE);
        if (code == null || !code.startsWith(KAPOUCH_PREFIX)) {
            if (listener != null) listener.onNonKapouchCode();
            return;
        }
        if (busy) return;
        busy = true;
        if (listener != null) listener.onCodeAccepted();

        final Context appContext = context.getApplicationContext();
        final PendingResult pending = goAsync();
        new Thread(() -> {
            boolean ok = false;
            String message;
            try {
                LoyaltyApi.Result result = LoyaltyApi.lookup(appContext, code);
                SharedPreferences prefs = appContext.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
                if (result.ok) {
                    ok = true;
                    DecimalFormat format = new DecimalFormat("0.##");
                    String balance = format.format(result.balance);
                    message = result.name + " · " + balance + " ★" + (result.linked ? " · клиент определён" : "");
                    if (result.drinkProgramEnabled) {
                        if (result.availableRewards > 0) {
                            message += "\n🎁 Бесплатный напиток" + (result.giftCap > 0 ? " до " + format.format(result.giftCap) + " ₽" : " доступен");
                        } else {
                            message += "\n☕ Карта напитков: " + result.drinkProgress + "/" + result.drinkRequired;
                        }
                    }
                    prefs.edit()
                            .putString(KEY_ACTIVE_CUSTOMER_NAME, result.name)
                            .putString(KEY_ACTIVE_CUSTOMER_BALANCE, balance)
                            .putLong(KEY_ACTIVE_CUSTOMER_AT, System.currentTimeMillis())
                            .putString(MainActivity.KEY_LAST_TITLE, "Клиент Kapouch определён")
                            .putString(MainActivity.KEY_LAST_DESCRIPTION, message)
                            .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                            .apply();
                } else {
                    message = "Kapouch: " + result.error;
                    prefs.edit()
                            .putString(MainActivity.KEY_LAST_TITLE, "QR-карта Kapouch")
                            .putString(MainActivity.KEY_LAST_DESCRIPTION, result.error)
                            .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                            .apply();
                }
            } catch (RuntimeException e) {
                String detail = e.getMessage();
                if (detail == null || detail.trim().isEmpty()) detail = e.getClass().getSimpleName();
                message = "Kapouch: " + detail;
            }

            final boolean finalOk = ok;
            final String finalMessage = message;
            new Handler(Looper.getMainLooper()).post(() -> {
                busy = false;
                if (listener != null) listener.onLookupFinished(finalOk, finalMessage);
            });
            pending.finish();
        }, "kapouch-customer-scan").start();
    }
}
