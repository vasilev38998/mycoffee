package ru.kapouch.evotor;

import android.os.Bundle;
import android.support.annotation.Nullable;

import java.util.Collections;

import ru.evotor.framework.core.IntegrationActivity;
import ru.evotor.framework.receipt.formation.event.ReturnPositionsForBarcodeRequestedEvent;

/**
 * Invisible integration continuation used only for KAPOUCH:LOYALTY:* codes.
 *
 * Evotor's documented barcode flow asks the integration that declared
 * iCanCreateNewProduct=true to continue creation through an IntegrationActivity.
 * For a loyalty QR there is intentionally no product to create, so this activity
 * immediately completes the continuation with an empty position list and
 * iCanCreateNewProduct=false. Theme.NoDisplay keeps the cashier on Sell screen.
 */
public final class LoyaltyBarcodeConsumedActivity extends IntegrationActivity {
    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setIntegrationResult(new ReturnPositionsForBarcodeRequestedEvent.Result(
                Collections.emptyList(),
                false));
        finish();
    }
}
