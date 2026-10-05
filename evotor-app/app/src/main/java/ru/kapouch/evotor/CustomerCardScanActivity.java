package ru.kapouch.evotor;

import android.content.IntentFilter;
import android.graphics.Color;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import ru.evotor.framework.core.IntegrationActivity;

/**
 * Dedicated loyalty-card scan mode.
 *
 * While this activity is in the foreground Evotor's stock Sell screen is paused,
 * so the physical scanner is used only to read the Kapouch loyalty QR instead of
 * treating the QR as a product barcode. This avoids the native "code not found in
 * product database" lookup/message that Evotor performs on the Sell screen.
 */
public final class CustomerCardScanActivity extends IntegrationActivity {
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private CustomerScanReceiver receiver;
    private boolean registered;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        int pad = dp(24);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER_HORIZONTAL);
        root.setPadding(pad, pad, pad, pad);
        root.setBackgroundColor(Color.rgb(250, 248, 245));

        TextView title = new TextView(this);
        title.setText("Карта клиента Kapouch");
        title.setTextSize(28);
        title.setTextColor(Color.rgb(30, 26, 22));
        title.setGravity(Gravity.CENTER_HORIZONTAL);
        root.addView(title, matchWrap());

        TextView hint = new TextView(this);
        hint.setText("Отсканируйте QR-карту клиента сейчас. В этом режиме код не отправляется в поиск товаров Эвотора.");
        hint.setTextSize(17);
        hint.setTextColor(Color.DKGRAY);
        hint.setGravity(Gravity.CENTER_HORIZONTAL);
        hint.setPadding(0, dp(18), 0, dp(22));
        root.addView(hint, matchWrap());

        status = new TextView(this);
        status.setText("Ждём QR-карту Kapouch…");
        status.setTextSize(18);
        status.setTextColor(Color.rgb(55, 47, 39));
        status.setGravity(Gravity.CENTER);
        status.setPadding(dp(18), dp(20), dp(18), dp(20));
        status.setBackgroundColor(Color.WHITE);
        root.addView(status, matchWrap());

        Button cancel = new Button(this);
        cancel.setText("ОТМЕНИТЬ");
        cancel.setTextSize(16);
        cancel.setMinHeight(dp(56));
        cancel.setOnClickListener(v -> finish());
        LinearLayout.LayoutParams cancelParams = matchWrap();
        cancelParams.setMargins(0, dp(20), 0, 0);
        cancel.setLayoutParams(cancelParams);
        root.addView(cancel);

        setContentView(root);
    }

    @Override
    protected void onResume() {
        super.onResume();
        startScanner();
    }

    @Override
    protected void onPause() {
        stopScanner();
        super.onPause();
    }

    @Override
    public void finish() {
        // The payment-screen action was opened through Evotor's integration
        // protocol. Return an empty success bundle so the request is always
        // completed cleanly after scan or cancel.
        setIntegrationResult(new Bundle());
        super.finish();
    }

    private void startScanner() {
        if (registered) return;
        receiver = new CustomerScanReceiver(new CustomerScanReceiver.Listener() {
            @Override
            public void onCodeAccepted() {
                if (status != null) status.setText("Карта считана. Проверяем клиента в Kapouch…");
            }

            @Override
            public void onNonKapouchCode() {
                if (status != null) status.setText("Это не QR-карта Kapouch. Отсканируйте карту клиента ещё раз.");
                Toast.makeText(CustomerCardScanActivity.this, "Ожидается QR-карта Kapouch", Toast.LENGTH_SHORT).show();
            }

            @Override
            public void onLookupFinished(boolean ok, String message) {
                if (status != null) {
                    status.setText(ok ? message : message + "\n\nМожно повторить сканирование.");
                }
                Toast.makeText(CustomerCardScanActivity.this, message, Toast.LENGTH_LONG).show();
                if (ok) {
                    // Stop listening before returning to Evotor so a second scan
                    // cannot accidentally replace the customer for this receipt.
                    stopScanner();
                    mainHandler.postDelayed(CustomerCardScanActivity.this::finish, 450L);
                }
            }
        });

        try {
            registerReceiver(
                    receiver,
                    new IntentFilter(CustomerScanReceiver.ACTION_SCANNED),
                    "ru.evotor.devices.SCANNER_SENDER",
                    null);
            registered = true;
            if (status != null) status.setText("Ждём QR-карту Kapouch…");
        } catch (RuntimeException error) {
            receiver = null;
            registered = false;
            String detail = error.getMessage();
            if (detail == null || detail.trim().isEmpty()) detail = error.getClass().getSimpleName();
            if (status != null) status.setText("Сканер Эвотора недоступен: " + detail);
        }
    }

    private void stopScanner() {
        if (registered && receiver != null) {
            try {
                unregisterReceiver(receiver);
            } catch (RuntimeException ignored) {
            }
        }
        registered = false;
        receiver = null;
    }

    private LinearLayout.LayoutParams matchWrap() {
        return new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
    }

    private int dp(int value) {
        float density = getResources().getDisplayMetrics().density;
        return Math.round(value * density);
    }
}
