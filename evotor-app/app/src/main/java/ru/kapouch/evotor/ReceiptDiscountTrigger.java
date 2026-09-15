package ru.kapouch.evotor;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ResolveInfo;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;

import java.util.List;

import ru.evotor.IBundlable;
import ru.evotor.framework.core.ICanStartActivity;
import ru.evotor.framework.core.IntegrationManagerFuture;
import ru.evotor.framework.core.IntegrationManagerImpl;
import ru.evotor.framework.receipt.Receipt;
import ru.evotor.framework.receipt.ReceiptApi;

/**
 * Compatibility bridge for Evotor's newer automatic discount trigger.
 *
 * Kapouch intentionally keeps integration-library v0.4.36 because the current
 * Evotor app still targets the legacy Android stack. Newer Evotor firmware
 * exposes ACTION_TRIGGER_RECEIPT_DISCOUNT_EVENT at runtime, so we can invoke it
 * through the old public IntegrationManager API without upgrading the whole SDK.
 */
final class ReceiptDiscountTrigger {
    private static final String ACTION_TRIGGER = "evotor.intent.action.ACTION_TRIGGER_RECEIPT_DISCOUNT_EVENT";

    interface Listener {
        void onFinished(boolean applied, String message);
    }

    private ReceiptDiscountTrigger() {}

    static void trigger(Context context, String loyaltyCode, Listener listener) {
        if (context == null || loyaltyCode == null || !loyaltyCode.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) {
            finish(listener, false, "Карта Kapouch не определена");
            return;
        }
        Context app = context.getApplicationContext();
        Receipt receipt;
        try {
            receipt = ReceiptApi.getReceipt(app, Receipt.Type.SELL);
        } catch (Throwable error) {
            finish(listener, false, "Не удалось прочитать открытый чек");
            return;
        }
        if (receipt == null || receipt.getHeader() == null || receipt.getPositions() == null || receipt.getPositions().isEmpty()) {
            finish(listener, false, "Открытый чек пуст — скидка применится при переходе к оплате");
            return;
        }

        List<ResolveInfo> services = app.getPackageManager().queryIntentServices(new Intent(ACTION_TRIGGER), 0);
        if (services == null || services.isEmpty()) {
            finish(listener, false, "Скидка применится при переходе к оплате");
            return;
        }
        ResolveInfo target = services.get(0);
        if (target.serviceInfo == null) {
            finish(listener, false, "Сервис скидок Эвотора недоступен");
            return;
        }
        ComponentName evotorService = new ComponentName(target.serviceInfo.packageName, target.serviceInfo.name);
        ComponentName kapouchDiscountService = new ComponentName(app, KapouchDiscountService.class);
        TriggerEvent event = new TriggerEvent(kapouchDiscountService, loyaltyCode);
        IntegrationManagerImpl manager = new IntegrationManagerImpl(app);
        ICanStartActivity activityStarter = intent -> {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            app.startActivity(intent);
        };
        manager.call(ACTION_TRIGGER, evotorService, event, activityStarter, future -> {
            boolean ok = false;
            String message = "Скидка Kapouch не применена";
            try {
                IntegrationManagerFuture.Result result = future == null ? null : future.getResult();
                if (result != null && result.getError() == null) {
                    ok = true;
                    // KapouchDiscountService owns server confirmation and calls
                    // LoyaltyDiscountApi.confirm after Evotor accepts its result.
                    // Keep this main-thread integration callback free of network I/O.
                    message = "Скидка Kapouch рассчитана автоматически";
                } else if (result != null && result.getError() != null && result.getError().getMessage() != null) {
                    message = result.getError().getMessage();
                }
            } catch (Throwable error) {
                if (error.getMessage() != null && !error.getMessage().trim().isEmpty()) message = error.getMessage();
            }
            finish(listener, ok, message);
        }, new Handler(Looper.getMainLooper()));
    }

    private static void finish(Listener listener, boolean applied, String message) {
        if (listener == null) return;
        new Handler(Looper.getMainLooper()).post(() -> listener.onFinished(applied, message));
    }

    private static final class TriggerEvent implements IBundlable {
        private final ComponentName componentName;
        private final String loyaltyCardId;

        TriggerEvent(ComponentName componentName, String loyaltyCardId) {
            this.componentName = componentName;
            this.loyaltyCardId = loyaltyCardId;
        }

        @Override
        public Bundle toBundle() {
            Bundle bundle = new Bundle();
            bundle.putParcelable("KEY_COMPONENT_NAME", componentName);
            bundle.putString("KEY_RECEIPT_TYPE", Receipt.Type.SELL.name());
            bundle.putString("KEY_LOYALTY_CARD_ID", loyaltyCardId);
            return bundle;
        }
    }
}
