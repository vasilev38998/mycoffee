package ru.kapouch.evotor;

import android.content.Intent;

import java.util.Collections;

import ru.evotor.framework.receipt.formation.event.DiscountScreenAdditionalItemsEvent;
import ru.evotor.framework.receipt.formation.event.ReturnPositionsForBarcodeRequestedEvent;
import ru.evotor.framework.receipt.formation.event.handler.service.SellIntegrationService;

/**
 * Receives scanner events while the stock Evotor "Sell" screen is open.
 *
 * Kapouch only reacts to its own loyalty QR prefix. Every other barcode is
 * skipped so Evotor and other integrations can process it normally.
 */
public final class KapouchSellIntegrationService extends SellIntegrationService {
    @Override
    public ReturnPositionsForBarcodeRequestedEvent.Result handleEvent(
            ReturnPositionsForBarcodeRequestedEvent event) {
        if (event == null) return null;

        String code = event.getBarcode();
        if (code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) return null;

        if (event.getCreatingNewProduct()) {
            // Evotor searches its own catalog in parallel with integrations. If
            // nobody claims the unknown barcode, the stock Sell screen opens
            // "Товар не найден / Добавить товар". Claim the creation phase for
            // Kapouch QR and immediately complete it with no receipt position.
            // This consumes only KAPOUCH:LOYALTY:*; normal product barcodes are
            // still completely transparent to Kapouch.
            startIntegrationActivity(new Intent(this, LoyaltyBarcodeConsumedActivity.class));
            return null;
        }

        CustomerLinkService.enqueue(this, code);

        // The first pass tells Evotor that Kapouch owns the fallback for this
        // barcode. Because the QR is a loyalty card, not a product, no position
        // is added. If Evotor's own catalog also finds nothing, it calls us once
        // more with creatingNewProduct=true instead of launching its native
        // product-creation popup.
        return new ReturnPositionsForBarcodeRequestedEvent.Result(
                Collections.emptyList(),
                true);
    }

    @Override
    public Void handleEvent(DiscountScreenAdditionalItemsEvent event) {
        // The stock Sell screen always performs its own product lookup for every
        // physical scan, including a loyalty QR. There is no supported flag that
        // disables that lookup for one barcode. Give the cashier a dedicated
        // scan mode from Evotor's payment screen instead: while our activity is
        // foreground, the Sell screen is paused and the QR goes only to Kapouch.
        Intent intent = new Intent(this, CustomerCardScanActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent);
        return null;
    }
}
