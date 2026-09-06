package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

final class OrderStore {
    private static final String KEY_ORDERS = "active_orders_json_v1";
    private static final int MAX_ORDERS = 20;

    private OrderStore() {}

    static synchronized OrderRecord upsertNew(Context context, String orderId, String orderNumber, String title,
                                              String description, String actionUrl, String actionToken, long receivedAt) {
        List<OrderRecord> current = load(context);
        OrderRecord record = null;
        List<OrderRecord> next = new ArrayList<>();
        for (OrderRecord item : current) {
            if (item.orderId.equals(orderId)) {
                record = item;
            } else {
                next.add(item);
            }
        }
        if (record == null) record = new OrderRecord();
        record.orderId = orderId;
        record.orderNumber = orderNumber;
        record.title = title;
        record.description = description;
        record.status = "new";
        record.actionUrl = actionUrl;
        record.actionToken = actionToken;
        record.receivedAt = receivedAt;
        record.lastError = "";
        record.reminderCount = 0;
        next.add(0, record);
        save(context, next);
        return record;
    }

    static synchronized List<OrderRecord> list(Context context) {
        return load(context);
    }

    static synchronized OrderRecord find(Context context, String orderId) {
        for (OrderRecord record : load(context)) {
            if (record.orderId.equals(orderId)) return record;
        }
        return null;
    }

    static synchronized OrderRecord updateStatus(Context context, String orderId, String status) {
        List<OrderRecord> records = load(context);
        OrderRecord found = null;
        for (OrderRecord record : records) {
            if (!record.orderId.equals(orderId)) continue;
            record.status = status == null || status.isEmpty() ? record.status : status;
            record.lastError = "";
            if ("ready".equals(record.status) || "completed".equals(record.status) || "cancelled".equals(record.status)) {
                record.actionToken = "";
            }
            found = record;
            break;
        }
        save(context, records);
        return found;
    }

    static synchronized OrderRecord setError(Context context, String orderId, String error) {
        List<OrderRecord> records = load(context);
        OrderRecord found = null;
        for (OrderRecord record : records) {
            if (!record.orderId.equals(orderId)) continue;
            record.lastError = error == null ? "" : error;
            found = record;
            break;
        }
        save(context, records);
        return found;
    }

    static synchronized OrderRecord incrementReminder(Context context, String orderId) {
        List<OrderRecord> records = load(context);
        OrderRecord found = null;
        for (OrderRecord record : records) {
            if (!record.orderId.equals(orderId)) continue;
            record.reminderCount++;
            found = record;
            break;
        }
        save(context, records);
        return found;
    }

    private static List<OrderRecord> load(Context context) {
        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        String raw = prefs.getString(KEY_ORDERS, "[]");
        List<OrderRecord> result = new ArrayList<>();
        try {
            JSONArray array = new JSONArray(raw == null ? "[]" : raw);
            long now = System.currentTimeMillis();
            for (int i = 0; i < array.length() && result.size() < MAX_ORDERS; i++) {
                JSONObject o = array.optJSONObject(i);
                if (o == null) continue;
                OrderRecord record = fromJson(o);
                if (record.orderId.isEmpty()) continue;
                boolean finished = "ready".equals(record.status) || "completed".equals(record.status) || "cancelled".equals(record.status);
                if (finished && record.receivedAt > 0 && now - record.receivedAt > 12L * 60L * 60L * 1000L) continue;
                result.add(record);
            }
        } catch (Exception ignored) {
        }
        return result;
    }

    private static void save(Context context, List<OrderRecord> records) {
        JSONArray array = new JSONArray();
        int count = 0;
        for (OrderRecord record : records) {
            if (count >= MAX_ORDERS) break;
            array.put(toJson(record));
            count++;
        }
        context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE)
                .edit().putString(KEY_ORDERS, array.toString()).apply();
    }

    private static OrderRecord fromJson(JSONObject o) {
        OrderRecord record = new OrderRecord();
        record.orderId = o.optString("order_id", "");
        record.orderNumber = o.optString("order_number", "");
        record.title = o.optString("title", "");
        record.description = o.optString("description", "");
        record.status = o.optString("status", "new");
        record.actionUrl = o.optString("action_url", "");
        record.actionToken = o.optString("action_token", "");
        record.lastError = o.optString("last_error", "");
        record.receivedAt = o.optLong("received_at", 0L);
        record.reminderCount = o.optInt("reminder_count", 0);
        return record;
    }

    private static JSONObject toJson(OrderRecord record) {
        JSONObject o = new JSONObject();
        try {
            o.put("order_id", record.orderId);
            o.put("order_number", record.orderNumber);
            o.put("title", record.title);
            o.put("description", record.description);
            o.put("status", record.status);
            o.put("action_url", record.actionUrl);
            o.put("action_token", record.actionToken);
            o.put("last_error", record.lastError);
            o.put("received_at", record.receivedAt);
            o.put("reminder_count", record.reminderCount);
        } catch (Exception ignored) {
        }
        return o;
    }
}
