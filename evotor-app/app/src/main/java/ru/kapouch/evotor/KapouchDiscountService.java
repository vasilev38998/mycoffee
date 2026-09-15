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
                ReceiptDiscountEvent discountEvent = ReceiptDiscountEvent.create(bundle);
                if (bundle == null || discountEvent == null) {
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
                String requestedReceiptUuid = discountEvent.getReceiptUuid();
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

                BigDecimal currentDiscount = discountEvent.getDiscount() == null
                        ? BigDecimal.ZERO
                        : discountEvent.getDiscount().max(BigDecimal.ZERO);
                if (!quote.gift || quote.discount <= 0d) {
                    // Never erase a discount that may already have been applied by
                    // another integration before Kapouch was asked to calculate.
                    callback.onResult(new ReceiptDiscountEventResult(currentDiscount, null, Collections.emptyList()));
                    return;
                }

                BigDecimal giftDiscount = BigDecimal.valueOf(quote.discount).setScale(2, BigDecimal.ROUND_HALF_UP);
                BigDecimal resultingDiscount = quote.alreadyApplied
                        ? currentDiscount.max(giftDiscount)
                        : currentDiscount.add(giftDiscount);
                String detail = "Подарок Kapouch: −" + giftDiscount.toPlainString() + " ₽";
                if (quote.sameOrderUnlock) detail += " · заработан этим чеком";
                saveLast("Скидка применена", detail);

                // Once Evotor accepts the result bundle, mark the quoted reward as
                // applied on the server. The eventual imported sale finalizes the
                // reward and removes one stamp for the gifted drink.
                callback.onResult(new ReceiptDiscountEventResult(resultingDiscount, null, Collections.emptyList()));
                if (!quote.alreadyApplied) {
                    final String receiptUuid = receipt.getHeader().getUuid();
                    final Context app = getApplicationContext();
                    new Thread(() -> LoyaltyDiscountApi.confirm(app, receiptUuid), "kapouch-discount-confirm").start();
                }
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
