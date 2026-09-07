package ru.kapouch.evotor;

import android.app.Activity;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Switch;
import android.widget.TextView;
import android.widget.Toast;

import java.text.DateFormat;
import java.util.Date;
import java.util.List;

public class MainActivity extends Activity {
    public static final String PREFS = "kapouch_orders";
    public static final String KEY_ENABLED = "notifications_enabled";
    public static final String KEY_LAST_TITLE = "last_title";
    public static final String KEY_LAST_DESCRIPTION = "last_description";
    public static final String KEY_LAST_AT = "last_at";

    private LinearLayout ordersContainer;
    private TextView lastMessage;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        int pad = dp(24);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);
        root.setBackgroundColor(Color.rgb(250, 248, 245));

        TextView title = new TextView(this);
        title.setText("Kapouch Orders");
        title.setTextSize(30);
        title.setTextColor(Color.rgb(30, 26, 22));
        title.setPadding(0, 0, 0, dp(8));
        root.addView(title, matchWrap());

        TextView intro = new TextView(this);
        intro.setText("Рабочий экран бариста: новые PWA-заказы, принятие и готовность.");
        intro.setTextSize(16);
        intro.setTextColor(Color.DKGRAY);
        intro.setPadding(0, 0, 0, dp(18));
        root.addView(intro);

        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        Switch enabled = new Switch(this);
        enabled.setText("Громко уведомлять о новых заказах на этом Эвоторе");
        enabled.setTextSize(18);
        enabled.setChecked(prefs.getBoolean(KEY_ENABLED, true));
        enabled.setPadding(0, dp(10), 0, dp(10));
        enabled.setOnCheckedChangeListener((buttonView, isChecked) -> {
            prefs.edit().putBoolean(KEY_ENABLED, isChecked).apply();
            for (OrderRecord order : OrderStore.list(this)) {
                if (!isChecked) {
                    OrderNotifications.cancel(this, order.orderId);
                } else if ("new".equals(order.status) || "preparing".equals(order.status)) {
                    OrderNotifications.showCurrent(this, order);
                    if ("new".equals(order.status)) OrderNotifications.scheduleReminder(this, order.orderId);
                }
            }
        });
        root.addView(enabled, matchWrap());

        TextView note = new TextView(this);
        note.setText("Новый заказ звучит сразу и напоминает ещё до трёх раз, пока его не примут. После «Заказ готов» статус меняется в Kapouch, а клиент получает уведомление, если подписан на push.");
        note.setTextSize(14);
        note.setTextColor(Color.GRAY);
        note.setPadding(0, dp(6), 0, dp(26));
        root.addView(note);

        TextView activeTitle = sectionTitle("Активные заказы");
        root.addView(activeTitle);

        ordersContainer = new LinearLayout(this);
        ordersContainer.setOrientation(LinearLayout.VERTICAL);
        root.addView(ordersContainer, matchWrap());

        TextView lastTitle = sectionTitle("Последнее полученное сообщение");
        lastTitle.setPadding(0, dp(24), 0, dp(8));
        root.addView(lastTitle);

        lastMessage = new TextView(this);
        lastMessage.setTextSize(15);
        lastMessage.setTextColor(Color.DKGRAY);
        lastMessage.setGravity(Gravity.START);
        lastMessage.setPadding(dp(16), dp(16), dp(16), dp(16));
        lastMessage.setBackgroundColor(Color.WHITE);
        root.addView(lastMessage, matchWrap());

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        setContentView(scroll);
        refreshAll();
    }

    @Override
    protected void onResume() {
        super.onResume();
        refreshAll();
    }

    private void refreshAll() {
        refreshOrders();
        refreshLastMessage();
    }

    private void refreshOrders() {
        if (ordersContainer == null) return;
        ordersContainer.removeAllViews();
        List<OrderRecord> orders = OrderStore.list(this);
        if (orders.isEmpty()) {
            TextView empty = new TextView(this);
            empty.setText("Активных заказов пока нет.");
            empty.setTextSize(16);
            empty.setTextColor(Color.GRAY);
            empty.setPadding(0, dp(12), 0, dp(12));
            ordersContainer.addView(empty);
            return;
        }
        for (OrderRecord order : orders) ordersContainer.addView(orderCard(order));
    }

    private LinearLayout orderCard(OrderRecord order) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(18), dp(16), dp(18), dp(16));
        LinearLayout.LayoutParams params = matchWrap();
        params.setMargins(0, dp(8), 0, dp(8));
        card.setLayoutParams(params);
        card.setBackground(cardBackground(order.status));

        TextView header = new TextView(this);
        header.setText((order.orderNumber.isEmpty() ? "Заказ" : order.orderNumber) + " · " + statusLabel(order.status));
        header.setTextSize(22);
        header.setTextColor(Color.rgb(28, 25, 22));
        header.setGravity(Gravity.START);
        card.addView(header, matchWrap());

        TextView details = new TextView(this);
        details.setText(order.description == null ? "" : order.description);
        details.setTextSize(17);
        details.setTextColor(Color.DKGRAY);
        details.setPadding(0, dp(8), 0, dp(8));
        card.addView(details, matchWrap());

        if (order.receivedAt > 0) {
            TextView time = new TextView(this);
            time.setText("Получен: " + DateFormat.getTimeInstance(DateFormat.MEDIUM).format(new Date(order.receivedAt)));
            time.setTextSize(13);
            time.setTextColor(Color.GRAY);
            time.setPadding(0, 0, 0, dp(8));
            card.addView(time, matchWrap());
        }

        if (order.lastError != null && !order.lastError.isEmpty()) {
            TextView error = new TextView(this);
            error.setText("⚠ " + order.lastError);
            error.setTextSize(14);
            error.setTextColor(Color.rgb(170, 45, 38));
            error.setPadding(0, dp(4), 0, dp(8));
            card.addView(error, matchWrap());
        }

        boolean actionable = order.actionUrl != null && order.actionUrl.startsWith("https://") && order.actionToken != null && !order.actionToken.isEmpty();
        if ("new".equals(order.status)) {
            if (actionable) card.addView(actionButton(order, "accept", "ПРИНЯТЬ ЗАКАЗ", Color.rgb(38, 128, 72)));
            else card.addView(infoText("Для кнопок управления нужен push от обновлённого сервера Kapouch."));
        } else if ("preparing".equals(order.status)) {
            if (actionable) card.addView(actionButton(order, "ready", "ЗАКАЗ ГОТОВ", Color.rgb(219, 137, 28)));
            else card.addView(infoText("Ключ действия недоступен. Измените статус заказа в веб-панели Kapouch."));
        } else if ("ready".equals(order.status)) {
            card.addView(infoText("✓ Готов. Клиент видит новый статус; push отправлен в очередь уведомлений."));
        } else if ("completed".equals(order.status)) {
            card.addView(infoText("✓ Заказ выдан."));
        } else if ("cancelled".equals(order.status)) {
            card.addView(infoText("Заказ отменён."));
        }
        return card;
    }

    private Button actionButton(OrderRecord order, String action, String text, int color) {
        Button button = new Button(this);
        button.setText(text);
        button.setTextSize(18);
        button.setTextColor(Color.WHITE);
        button.setMinHeight(dp(58));
        GradientDrawable background = new GradientDrawable();
        background.setColor(color);
        background.setCornerRadius(dp(14));
        button.setBackground(background);
        button.setOnClickListener(v -> performAction(order, action, button));
        LinearLayout.LayoutParams params = matchWrap();
        params.setMargins(0, dp(6), 0, 0);
        button.setLayoutParams(params);
        return button;
    }

    private void performAction(OrderRecord order, String action, Button button) {
        button.setEnabled(false);
        button.setText("Отправляем…");
        new Thread(() -> {
            OrderApi.Result result = OrderApi.perform(getApplicationContext(), order, action);
            if (result.ok) {
                OrderRecord updated = OrderStore.updateStatus(this, order.orderId, result.status);
                if (updated != null) {
                    if ("preparing".equals(updated.status)) OrderNotifications.showPreparing(this, updated);
                    else OrderNotifications.cancel(this, updated.orderId);
                }
            } else {
                OrderStore.setError(this, order.orderId, result.error);
            }
            runOnUiThread(() -> {
                Toast.makeText(this, result.ok ? ("Статус: " + result.statusLabel) : result.error, Toast.LENGTH_LONG).show();
                refreshAll();
            });
        }, "kapouch-order-ui-action").start();
    }

    private TextView sectionTitle(String text) {
        TextView section = new TextView(this);
        section.setText(text);
        section.setTextSize(19);
        section.setTextColor(Color.rgb(30, 26, 22));
        section.setPadding(0, 0, 0, dp(8));
        return section;
    }

    private TextView infoText(String text) {
        TextView view = new TextView(this);
        view.setText(text);
        view.setTextSize(14);
        view.setTextColor(Color.GRAY);
        view.setPadding(0, dp(8), 0, dp(2));
        return view;
    }

    private GradientDrawable cardBackground(String status) {
        int color;
        if ("new".equals(status)) color = Color.rgb(255, 241, 204);
        else if ("preparing".equals(status)) color = Color.rgb(230, 243, 255);
        else if ("ready".equals(status)) color = Color.rgb(224, 247, 232);
        else color = Color.WHITE;
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(color);
        drawable.setCornerRadius(dp(16));
        drawable.setStroke(dp(1), Color.rgb(225, 218, 210));
        return drawable;
    }

    private String statusLabel(String status) {
        if ("new".equals(status)) return "НОВЫЙ";
        if ("preparing".equals(status)) return "ГОТОВИТСЯ";
        if ("ready".equals(status)) return "ГОТОВ";
        if ("completed".equals(status)) return "ВЫДАН";
        if ("cancelled".equals(status)) return "ОТМЕНЁН";
        return status == null ? "" : status.toUpperCase();
    }

    private void refreshLastMessage() {
        if (lastMessage == null) return;
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        String title = prefs.getString(KEY_LAST_TITLE, "");
        String description = prefs.getString(KEY_LAST_DESCRIPTION, "");
        long at = prefs.getLong(KEY_LAST_AT, 0L);
        if (title == null || title.isEmpty()) {
            lastMessage.setText("Пока сообщений не было. После настройки отправьте тестовое уведомление из Kapouch.");
            return;
        }
        String when = at > 0 ? DateFormat.getDateTimeInstance(DateFormat.SHORT, DateFormat.MEDIUM).format(new Date(at)) : "";
        lastMessage.setText(title + (description == null || description.isEmpty() ? "" : "\n\n" + description) + (when.isEmpty() ? "" : "\n\nПолучено: " + when));
    }

    private LinearLayout.LayoutParams matchWrap() {
        return new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
