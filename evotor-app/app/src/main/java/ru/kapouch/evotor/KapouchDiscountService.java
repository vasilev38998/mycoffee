package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.RemoteException;
import android.support.annotation.NonNull;
import android.support.annotation.Nullable;

import java.math.BigDecimal;
import java.util.Collections;
import java.util.HashMap;
import java.util.Map;

import ru.evotor.framework.core.IntegrationService;
import ru.evotor.framework.core.action.event.receipt.discount.ReceiptDiscountEvent;
import ru.evotor.framework.core.action.event.receipt.discount.ReceiptDiscountEventResult;
import ru.evotor.framework.core.action.processor.ActionProcessor;
import ru.evotor.framework.receipt.Receipt;
import ru.evotor.framework.receipt.ReceiptApi;

/**
 * Calculates Kapouch's drink reward when Evotor asks for a discount on the
 * current SELL receipt. The loyalty card id is forwarded by newer Evotor
 * firmware; a short-lived local copy is used as a compatibility fallback.
 */
public final class KapouchDiscountService extends IntegrationService {
    private static final long ACTIVE_CARD_TTL_MS = 30L * 60L * 1000L;

    @Nullable
    @Override
    protected Map<String, ActionProcessor> createProcessors() {
        Map<String, ActionProcessor> processors = new HashMap<>();
        processors.put(ReceiptDiscountEvent.NAME_SELL_RECEIPT, new ActionProcessor() {
            @Override
            public void process(@NonNull String action, @Nullable Bundle bundle, @NonNull Callback callback) throws RemoteException {
                if (bundle == null) {
                    callback.skip();
                    return;
                }

                String loyaltyCode = bundle.getString("loyaltyCardId", "");
                if (loyaltyCode == null || !loyaltyCode.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) {
                    loyaltyCode = recentSavedCode();
                }
                if (loyaltyCode == null || !loyaltyCode.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) {
                    callback.skip();
                    return;
                }

                Receipt receipt;
                try {
                    receipt = ReceiptApi.getReceipt(getApplicationContext(), Receipt.Type.SELL);
                } catch (Throwable error) {
                    callback.skip();
                    return;
                }
                if (receipt == null || receipt.getHeader() == null || receipt.getPositions() == null || receipt.getPositions().isEmpty()) {
                    callback.skip();
                    return;
                }
                String requestedReceiptUuid = bundle.getString("receiptUuid", "");
                if (requestedReceiptUuid != null && !requestedReceiptUuid.isEmpty()
                        && !requestedReceiptUuid.equals(receipt.getHeader().getUuid())) {
                    callback.skip();
                    return;
                }

                LoyaltyDiscountApi.Quote quote = LoyaltyDiscountApi.quote(getApplicationContext(), loyaltyCode, receipt);
                if (!quote.ok) {
                    saveLast("Скидка Kapouch", quote.error == null ? "Не удалось рассчитать скидку" : quote.error);
                    callback.skip();
                    return;
                }
                if (!quote.gift || quote.discount <= 0d) {
                    callback.onResult(new ReceiptDiscountEventResult(BigDecimal.ZERO, null, Collections.emptyList()));
                    return;
                }

                BigDecimal discount = BigDecimal.valueOf(quote.discount).setScale(2, BigDecimal.ROUND_HALF_UP);
                String detail = "Подарок Kapouch: −" + discount.toPlainString() + " ₽";
                if (quote.sameOrderUnlock) detail += " · заработан этим чеком";
                saveLast("Скидка применена", detail);
                callback.onResult(new ReceiptDiscountEventResult(discount, null, Collections.emptyList()));
            }
        });
        return processors;
    }

    private String recentSavedCode() {
        SharedPreferences prefs = getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        long activeAt = prefs.getLong(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT, 0L);
        if (activeAt <= 0L || System.currentTimeMillis() - activeAt > ACTIVE_CARD_TTL_MS) return "";
        return prefs.getString(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_CODE, "");
    }

    private void saveLast(String title, String description) {
        getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE)
                .edit()
                .putString(MainActivity.KEY_LAST_TITLE, title)
                .putString(MainActivity.KEY_LAST_DESCRIPTION, description == null ? "" : description)
                .putLong(MainActivity.KEY_LAST_AT, System.currentTimeMillis())
                .apply();
    }
}
