package ru.kapouch.evotor;

import android.content.Intent;
import android.os.Bundle;
import android.os.RemoteException;
import android.support.annotation.NonNull;
import android.support.annotation.Nullable;

import java.util.Collections;
import java.util.HashMap;
import java.util.Map;

import ru.evotor.framework.core.IntegrationService;
import ru.evotor.framework.core.action.processor.ActionProcessor;

/**
 * Payment-screen action for scanning a Kapouch customer card.
 *
 * integration-library v0.4.36 predates DiscountScreenAdditionalItemsEvent, but
 * its generic IntegrationService can still handle Evotor's action string. Using
 * Callback.startActivity keeps the implementation compatible with the terminal's
 * legacy Android/SDK stack while opening our dedicated scanner through Evotor's
 * normal integration protocol.
 */
public final class KapouchCustomerCardActionService extends IntegrationService {
    static final String ACTION_CUSTOMER_CARD = "ru.evotor.event.sell.DISCOUNT_SCREEN_ADDITIONAL_ITEMS";

    @Nullable
    @Override
    protected Map<String, ActionProcessor> createProcessors() {
        Map<String, ActionProcessor> processors = new HashMap<>();
        processors.put(ACTION_CUSTOMER_CARD, new ActionProcessor() {
            @Override
            public void process(@NonNull String action, @Nullable Bundle bundle, @NonNull Callback callback)
                    throws RemoteException {
                callback.startActivity(new Intent(
                        KapouchCustomerCardActionService.this,
                        CustomerCardScanActivity.class));
            }
        });
        return Collections.unmodifiableMap(processors);
    }
}
