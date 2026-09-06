package ru.kapouch.evotor;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;
import android.widget.Toast;

import java.text.DecimalFormat;

public class CustomerScanReceiver extends BroadcastReceiver {
    static final String ACTION_SCANNED = "ru.evotor.devices.ScannedCode";
    static final String EXTRA_SCANNED_CODE = "ScannedCode";
    static final String KAPOUCH_PREFIX = "KAPOUCH:LOYALTY:";
    private static final String KEY_ACTIVE_CUSTOMER_NAME = "active_customer_name";
    private static final String KEY_ACTIVE_CUSTOMER_BALANCE = "active_customer_balance";
    private static final String KEY_ACTIVE_CUSTOMER_AT = "active_customer_at";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (context == null || intent == null || !ACTION_SCANNED.equals(intent.getAction())) return;
        String code = intent.getStringExtra(EXTRA_SCANNED_CODE);
        if (code == null || !code.startsWith(KAPOUCH_PREFIX)) return;
        final Context appContext = context.getApplicationContext();
        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                LoyaltyApi.Result result = LoyaltyApi.lookup(appContext, code);
                SharedPreferences prefs = appContext.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
                String message;
                if (result.ok) {
                    String balance = new DecimalFormat("0.##").format(result.balance);
                    message = result.name + " · " + balance + " ★" + (result.linked ? " · клиент определён" : "");
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
                final String toast = message;
                new Handler(Looper.getMainLooper()).post(() -> Toast.makeText(appContext, toast, Toast.LENGTH_LONG).show());
            } finally {
                pending.finish();
            }
        }, "kapouch-customer-scan").start();
    }
}
