package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;
import android.widget.Toast;

import java.text.DecimalFormat;
import java.util.Collections;

import ru.evotor.framework.receipt.formation.event.ReturnPositionsForBarcodeRequestedEvent;
import ru.evotor.framework.receipt.formation.event.handler.service.SellIntegrationService;

/**
 * Handles Kapouch loyalty QR codes directly on Evotor's normal "Продажа" screen.
 *
 * Non-Kapouch barcodes are ignored so the stock Evotor product lookup keeps its
 * normal behaviour. A Kapouch QR is consumed without adding a product position:
 * the server registers the customer for the next SELL document on this terminal,
 * exactly like the fallback scanner inside MainActivity does today.
 */
public final class KapouchSellIntegrationService extends SellIntegrationService {
    private static final String KAPOUCH_PREFIX = "KAPOUCH:LOYALTY:";

    @Override
    public ReturnPositionsForBarcodeRequestedEvent.Result handleEvent(ReturnPositionsForBarcodeRequestedEvent event) {
        if (event == null || event.getCreatingNewProduct()) return null;

        String code = event.getBarcode();
        if (code == null) return null;
        code = code.trim();
        if (!code.startsWith(KAPOUCH_PREFIX)) return null;

        String message;
        try {
            LoyaltyApi.Result result = LoyaltyApi.lookup(getApplicationContext(), code);
            if (result.ok) {
                message = persistCustomer(result);
            } else {
                message = "Kapouch: " + result.error;
                persistLastMessage("QR-карта Kapouch", result.error);
            }
        } catch (RuntimeException error) {
            String detail = error.getMessage();
            if (detail == null || detail.trim().isEmpty()) detail = error.getClass().getSimpleName();
            message = "Kapouch: " + detail;
            persistLastMessage("QR-карта Kapouch", detail);
        }

        showToast(message);

        // Returning a non-null result marks this Kapouch QR as handled. No
        // receipt position is added and Evotor must not offer product creation.
        return new ReturnPositionsForBarcodeRequestedEvent.Result(Collections.emptyList(), false);
    }

    private String persistCustomer(LoyaltyApi.Result result) {
        DecimalFormat format = new DecimalFormat("0.##");
        String balance = format.format(result.balance);
        String message = result.name + " · " + balance + " ★ · клиент определён";
        if (result.drinkProgramEnabled) {
            if (result.availableRewards > 0) {
                message += " · подарок доступен";
            } else {
                message += " · напитки " + result.drinkProgress + "/" + result.drinkRequired;
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

    private void persistLastMessage(String title, String description) {
        getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE)
                .edit()
                .putString(MainActivity.KEY_LAST_TITLE, title)
                .putString(MainActivity.KEY_LAST_DESCRIPTION, description == null ? "" : description)
                .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                .apply();
    }

    private void showToast(final String message) {
        new Handler(Looper.getMainLooper()).post(() ->
                Toast.makeText(getApplicationContext(), message, Toast.LENGTH_LONG).show());
    }
}
