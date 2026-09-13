package ru.kapouch.evotor;

import android.content.Context;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

import javax.net.ssl.HttpsURLConnection;

final class OrderApi {
    private static final String KAPOUCH_HOST = "kapouch.store";
    private static final String ACTION_PATH = "/api/evotor_order_action.php";
    private static final String BRIDGE_URL = "https://kapouch.store/evotor-bridge?type=order";

    private OrderApi() {}

    static Result perform(Context context, OrderRecord order, String action) {
        if (context == null) return Result.error("Контекст Эвотора недоступен.");
        if (order == null) return Result.error("Заказ не найден на терминале.");
        if (order.actionUrl == null || order.actionUrl.trim().isEmpty()) return Result.error("В push нет адреса Kapouch.");
        if (order.actionToken == null || order.actionToken.isEmpty()) return Result.error("Ключ действия заказа отсутствует или уже истёк.");
        if (!"accept".equals(action) && !"ready".equals(action)) return Result.error("Неизвестное действие заказа.");

        try {
            URL url = new URL(order.actionUrl);
            if (!allowedActionUrl(url)) return Result.error("В push указан неподдерживаемый адрес Kapouch.");

            Response response = postAction(context, url, order, action);
            boolean bridgeUsed = false;
            if (response.status == 405) {
                bridgeUsed = true;
                response = bridgeAction(context, order, action);
            }

            JSONObject json = response.body.isEmpty() ? new JSONObject() : new JSONObject(response.body);
            if (response.status < 200 || response.status >= 300 || !json.optBoolean("ok", false)) {
                String fallback = "Kapouch вернул HTTP " + response.status;
                if (bridgeUsed) fallback = "Kapouch bridge вернул HTTP " + response.status;
                return Result.error(json.optString("error", fallback));
            }
            JSONObject orderJson = json.optJSONObject("order");
            if (orderJson == null) return Result.error("Kapouch не вернул новый статус заказа.");
            String newStatus = orderJson.optString("status", "");
            if (newStatus.isEmpty()) return Result.error("Kapouch вернул пустой статус заказа.");
            return Result.success(newStatus, orderJson.optString("status_label", newStatus));
        } catch (Exception e) {
            String message = e.getMessage();
            String type = e.getClass().getSimpleName();
            if (message == null || message.trim().isEmpty()) message = "Нет связи с Kapouch.";
            return Result.error("Evotor HTTPS " + type + ": " + message);
        }
    }

    private static Response postAction(Context context, URL url, OrderRecord order, String action) throws Exception {
        HttpsURLConnection connection = null;
        try {
            connection = open(context, url);
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Authorization", "Bearer " + order.actionToken);
            connection.setRequestProperty("X-Kapouch-Order-Token", order.actionToken);

            JSONObject body = new JSONObject();
            body.put("action", action);
            body.put("order_id", Integer.parseInt(order.orderId));
            byte[] payload = body.toString().getBytes(StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(payload.length);
            OutputStream output = connection.getOutputStream();
            output.write(payload);
            output.flush();
            output.close();
            return response(connection);
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private static Response bridgeAction(Context context, OrderRecord order, String action) throws Exception {
        HttpsURLConnection connection = null;
        try {
            connection = open(context, new URL(BRIDGE_URL));
            connection.setRequestMethod("GET");
            connection.setDoOutput(false);
            connection.setRequestProperty("Authorization", "Bearer " + order.actionToken);
            connection.setRequestProperty("X-Kapouch-Order-Token", order.actionToken);
            connection.setRequestProperty("X-Kapouch-Action", action);
            connection.setRequestProperty("X-Kapouch-Order-Id", order.orderId);
            connection.setRequestProperty("X-Kapouch-Evotor-Bridge", "1");
            return response(connection);
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private static HttpsURLConnection open(Context context, URL url) throws Exception {
        HttpURLConnection raw = (HttpURLConnection) url.openConnection();
        if (!(raw instanceof HttpsURLConnection)) {
            raw.disconnect();
            throw new IllegalArgumentException("Kapouch должен быть доступен только по HTTPS.");
        }
        HttpsURLConnection connection = (HttpsURLConnection) raw;
        connection.setSSLSocketFactory(EvotorTls.socketFactory(context.getApplicationContext()));
        connection.setHostnameVerifier(EvotorHostnameVerifier.INSTANCE);
        connection.setConnectTimeout(7000);
        connection.setReadTimeout(10000);
        connection.setInstanceFollowRedirects(false);
        connection.setUseCaches(false);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Cache-Control", "no-store");
        connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.21");
        return connection;
    }

    private static Response response(HttpURLConnection connection) throws Exception {
        int status = connection.getResponseCode();
        InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
        return new Response(status, readAll(stream));
    }

    static boolean allowedActionUrl(URL url) {
        if (url == null || !"https".equalsIgnoreCase(url.getProtocol())) return false;
        if (!KAPOUCH_HOST.equalsIgnoreCase(url.getHost())) return false;
        int port = url.getPort();
        if (port != -1 && port != 443) return false;
        return ACTION_PATH.equals(url.getPath()) && (url.getUserInfo() == null || url.getUserInfo().isEmpty());
    }

    private static String readAll(InputStream stream) throws Exception {
        if (stream == null) return "";
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder result = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) result.append(line);
        reader.close();
        return result.toString();
    }

    private static final class Response {
        final int status;
        final String body;

        Response(int status, String body) {
            this.status = status;
            this.body = body == null ? "" : body;
        }
    }

    static final class Result {
        final boolean ok;
        final String status;
        final String statusLabel;
        final String error;

        private Result(boolean ok, String status, String statusLabel, String error) {
            this.ok = ok;
            this.status = status;
            this.statusLabel = statusLabel;
            this.error = error;
        }

        static Result success(String status, String statusLabel) {
            return new Result(true, status, statusLabel, "");
        }

        static Result error(String error) {
            return new Result(false, "", "", error == null ? "Ошибка" : error);
        }
    }
}
