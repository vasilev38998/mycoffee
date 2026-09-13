package ru.kapouch.evotor;

import android.app.IntentService;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;
import android.widget.Toast;

import java.text.DecimalFormat;

/**
 * Performs the loyalty lookup outside Evotor's integration-service binder call.
 * The service is intentionally not exported and serializes scans one by one.
 */
public final class CustomerLinkService extends IntentService {
    private static final String EXTRA_CODE = "kapouch_loyalty_code";

    public CustomerLinkService() {
        super("kapouch-customer-link");
    }

    static void enqueue(Context context, String code) {
        if (context == null || code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) return;
        Intent intent = new Intent(context, CustomerLinkService.class);
        intent.putExtra(EXTRA_CODE, code);
        context.startService(intent);
    }

    @Override
    protected void onHandleIntent(Intent intent) {
        if (intent == null) return;
        String code = intent.getStringExtra(EXTRA_CODE);
        if (code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) return;

        String message;
        try {
            LoyaltyApi.Result result = LoyaltyApi.lookup(getApplicationContext(), code);
            message = saveResult(result);
        } catch (RuntimeException e) {
            String detail = e.getMessage();
            if (detail == null || detail.trim().isEmpty()) detail = e.getClass().getSimpleName();
            message = "Kapouch: " + detail;
            saveLastMessage("QR-карта Kapouch", detail);
        }

        final String toastMessage = message;
        new Handler(Looper.getMainLooper()).post(() ->
                Toast.makeText(getApplicationContext(), toastMessage, Toast.LENGTH_LONG).show());
    }

    private String saveResult(LoyaltyApi.Result result) {
        if (result == null) {
            saveLastMessage("QR-карта Kapouch", "Kapouch не вернул результат проверки клиента.");
            return "Kapouch: не удалось проверить клиента";
        }

        if (!result.ok) {
            String error = result.error == null || result.error.trim().isEmpty()
                    ? "Не удалось проверить клиента."
                    : result.error;
            saveLastMessage("QR-карта Kapouch", error);
            return "Kapouch: " + error;
        }

        DecimalFormat format = new DecimalFormat("0.##");
        String balance = format.format(result.balance);
        String message = result.name + " · " + balance + " ★"
                + (result.linked ? " · клиент определён" : "");
        if (result.drinkProgramEnabled) {
            if (result.availableRewards > 0) {
                message += "\n🎁 Бесплатный напиток"
                        + (result.giftCap > 0 ? " до " + format.format(result.giftCap) + " ₽" : " доступен");
            } else {
                message += "\n☕ Карта напитков: " + result.drinkProgress + "/" + result.drinkRequired;
            }
        }

        long now = System.currentTimeMillis();
        SharedPreferences prefs = getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        prefs.edit()
                .putString(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_NAME, result.name)
                .putString(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_BALANCE, balance)
                .putLong(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT, now)
                .putString(MainActivity.KEY_LAST_TITLE, "Клиент Kapouch определён")
                .putString(MainActivity.KEY_LAST_DESCRIPTION, message)
                .putLong(MainActivity.KEY_LAST_AT, now)
                .apply();
        return message;
    }

    private void saveLastMessage(String title, String description) {
        getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE)
                .edit()
                .putString(MainActivity.KEY_LAST_TITLE, title)
                .putString(MainActivity.KEY_LAST_DESCRIPTION, description == null ? "" : description)
                .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                .apply();
    }
}
