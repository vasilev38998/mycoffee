package ru.kapouch.evotor;

import java.util.Collections;

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
        if (event == null || event.getCreatingNewProduct()) return null;

        String code = event.getBarcode();
        if (code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) return null;

        CustomerLinkService.enqueue(this, code);

        // A loyalty card is not a receipt position. Kapouch has handled its own
        // data but deliberately does not add any product to the current receipt.
        return new ReturnPositionsForBarcodeRequestedEvent.Result(
                Collections.emptyList(),
                false);
    }
}
