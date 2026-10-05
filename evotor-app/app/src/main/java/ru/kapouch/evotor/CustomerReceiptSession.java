package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;

import ru.evotor.framework.receipt.Receipt;
import ru.evotor.framework.receipt.ReceiptApi;

/**
 * Keeps the scanned Kapouch card scoped to exactly one SELL receipt.
 *
 * A card may be scanned just before Evotor has created the receipt header, so an
 * unbound session is allowed to attach to the first SELL receipt that asks for a
 * discount. Once bound, a different receipt UUID invalidates the session.
 */
final class CustomerReceiptSession {
    static final String KEY_ACTIVE_RECEIPT_UUID = "active_customer_receipt_uuid";

    private CustomerReceiptSession() {}

    static void bindToCurrentReceipt(Context context, String loyaltyCode) {
        if (context == null || !isKapouchCode(loyaltyCode)) return;
        String receiptUuid = currentSellReceiptUuid(context);
        SharedPreferences.Editor editor = prefs(context).edit();
        if (receiptUuid.isEmpty()) editor.remove(KEY_ACTIVE_RECEIPT_UUID);
        else editor.putString(KEY_ACTIVE_RECEIPT_UUID, receiptUuid);
        editor.apply();
    }

    static String activeCodeForReceipt(Context context, String receiptUuid, long ttlMs) {
        if (context == null || receiptUuid == null || receiptUuid.trim().isEmpty()) return "";
        SharedPreferences preferences = prefs(context);
        String code = preferences.getString(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_CODE, "");
        long activeAt = preferences.getLong(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT, 0L);
        if (!isKapouchCode(code) || activeAt <= 0L || (ttlMs > 0L && System.currentTimeMillis() - activeAt > ttlMs)) {
            clear(context);
            return "";
        }

        String normalizedReceiptUuid = receiptUuid.trim();
        String storedReceiptUuid = preferences.getString(KEY_ACTIVE_RECEIPT_UUID, "");
        if (storedReceiptUuid == null || storedReceiptUuid.trim().isEmpty()) {
            // Compatibility path: the QR may have been scanned immediately before
            // Evotor created the receipt. Bind it once, to this first receipt only.
            preferences.edit().putString(KEY_ACTIVE_RECEIPT_UUID, normalizedReceiptUuid).apply();
            return code;
        }
        if (!normalizedReceiptUuid.equals(storedReceiptUuid)) {
            clear(context);
            return "";
        }
        return code;
    }

    static void clear(Context context) {
        if (context == null) return;
        prefs(context).edit()
                .remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_NAME)
                .remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_BALANCE)
                .remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_CODE)
                .remove(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT)
                .remove(KEY_ACTIVE_RECEIPT_UUID)
                .apply();
    }

    static String currentSellReceiptUuid(Context context) {
        if (context == null) return "";
        try {
            Receipt receipt = ReceiptApi.getReceipt(context.getApplicationContext(), Receipt.Type.SELL);
            if (receipt == null || receipt.getHeader() == null || receipt.getHeader().getUuid() == null) return "";
            return receipt.getHeader().getUuid().trim();
        } catch (Throwable ignored) {
            return "";
        }
    }

    private static SharedPreferences prefs(Context context) {
        return context.getApplicationContext().getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
    }

    private static boolean isKapouchCode(String code) {
        return code != null && code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX);
    }
}
