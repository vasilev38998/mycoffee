package ru.kapouch.evotor;

import android.content.ComponentName;
import android.content.Context;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.RemoteException;
import android.support.annotation.NonNull;
import android.support.annotation.Nullable;

import java.util.HashMap;
import java.util.Map;

import ru.evotor.framework.core.IntegrationService;
import ru.evotor.framework.core.action.processor.ActionProcessor;

/**
 * Newer Evotor firmware broadcasts this event when the cashier proceeds to the
 * payment-type screen. The legacy SDK bundled with Kapouch predates the typed
 * event, so the tiny compatibility processor returns the exact result bundle
 * expected by current Evotor versions.
 */
public final class KapouchDiscountRequiredService extends IntegrationService {
    static final String ACTION = "evo.v2.receipt.sell.receiptDiscountRequiredEvent";
    private static final long ACTIVE_CARD_TTL_MS = 30L * 60L * 1000L;

    @Nullable
    @Override
    protected Map<String, ActionProcessor> createProcessors() {
        Map<String, ActionProcessor> processors = new HashMap<>();
        processors.put(ACTION, new ActionProcessor() {
            @Override
            public void process(@NonNull String action, @Nullable Bundle bundle, @NonNull Callback callback) throws RemoteException {
                SharedPreferences prefs = getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
                String code = prefs.getString(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_CODE, "");
                long activeAt = prefs.getLong(CustomerScanReceiver.KEY_ACTIVE_CUSTOMER_AT, 0L);
                if (code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)
                        || activeAt <= 0L || System.currentTimeMillis() - activeAt > ACTIVE_CARD_TTL_MS) {
                    callback.skip();
                    return;
                }

                Bundle result = new Bundle();
                result.putParcelable("KEY_COMPONENT_NAME",
                        new ComponentName(getApplicationContext(), KapouchDiscountService.class));
                callback.onResult(result);
            }
        });
        return processors;
    }
}
