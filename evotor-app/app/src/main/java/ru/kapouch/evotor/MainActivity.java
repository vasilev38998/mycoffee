package ru.kapouch.evotor;

import android.app.Activity;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.os.Bundle;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Switch;
import android.widget.TextView;

import java.text.DateFormat;
import java.util.Date;

public class MainActivity extends Activity {
    public static final String PREFS = "kapouch_orders";
    public static final String KEY_ENABLED = "notifications_enabled";
    public static final String KEY_LAST_TITLE = "last_title";
    public static final String KEY_LAST_DESCRIPTION = "last_description";
    public static final String KEY_LAST_AT = "last_at";

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
        title.setTextSize(28);
        title.setTextColor(Color.rgb(30, 26, 22));
        title.setPadding(0, 0, 0, dp(8));
        root.addView(title, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextView intro = new TextView(this);
        intro.setText("Уведомления о новых заказах из клиентского PWA Kapouch.");
        intro.setTextSize(16);
        intro.setTextColor(Color.DKGRAY);
        intro.setPadding(0, 0, 0, dp(24));
        root.addView(intro);

        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        Switch enabled = new Switch(this);
        enabled.setText("Показывать уведомления на этом Эвоторе");
        enabled.setTextSize(18);
        enabled.setChecked(prefs.getBoolean(KEY_ENABLED, true));
        enabled.setPadding(0, dp(10), 0, dp(10));
        enabled.setOnCheckedChangeListener((buttonView, isChecked) -> prefs.edit().putBoolean(KEY_ENABLED, isChecked).apply());
        root.addView(enabled, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        TextView note = new TextView(this);
        note.setText("Это локальный переключатель терминала. Отправку с сервера можно независимо включать и выключать в Kapouch → Интеграции → Эвотор.");
        note.setTextSize(14);
        note.setTextColor(Color.GRAY);
        note.setPadding(0, dp(8), 0, dp(30));
        root.addView(note);

        TextView section = new TextView(this);
        section.setText("Последнее полученное сообщение");
        section.setTextSize(18);
        section.setTextColor(Color.rgb(30, 26, 22));
        section.setPadding(0, 0, 0, dp(8));
        root.addView(section);

        lastMessage = new TextView(this);
        lastMessage.setTextSize(16);
        lastMessage.setTextColor(Color.DKGRAY);
        lastMessage.setGravity(Gravity.START);
        lastMessage.setPadding(dp(16), dp(16), dp(16), dp(16));
        lastMessage.setBackgroundColor(Color.WHITE);
        root.addView(lastMessage, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        setContentView(scroll);
        refreshLastMessage();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (lastMessage != null) refreshLastMessage();
    }

    private void refreshLastMessage() {
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

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
