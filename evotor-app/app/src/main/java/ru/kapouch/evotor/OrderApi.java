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

    private OrderApi() {}

    static Result perform(Context context, OrderRecord order, String action) {
        if (context == null) return Result.error("Контекст Эвотора недоступен.");
        if (order == null) return Result.error("Заказ не найден на терминале.");
        if (order.actionUrl == null || order.actionUrl.trim().isEmpty()) return Result.error("В push нет адреса Kapouch.");
        if (order.actionToken == null || order.actionToken.isEmpty()) return Result.error("Ключ действия заказа отсутствует или уже истёк.");
        if (!"accept".equals(action) && !"ready".equals(action)) return Result.error("Неизвестное действие заказа.");

        HttpURLConnection connection = null;
        try {
            URL url = new URL(order.actionUrl);
            if (!allowedActionUrl(url)) return Result.error("В push указан неподдерживаемый адрес Kapouch.");
            connection = (HttpURLConnection) url.openConnection();
            if (!(connection instanceof HttpsURLConnection)) return Result.error("Kapouch должен быть доступен только по HTTPS.");
            HttpsURLConnection secure = (HttpsURLConnection) connection;
            secure.setSSLSocketFactory(EvotorTls.socketFactory(context.getApplicationContext()));

            connection.setRequestMethod("POST");
            connection.setConnectTimeout(7000);
            connection.setReadTimeout(10000);
            connection.setDoOutput(true);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Authorization", "Bearer " + order.actionToken);
            connection.setRequestProperty("X-Kapouch-Order-Token", order.actionToken);
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.14");

            JSONObject body = new JSONObject();
            body.put("action", action);
            body.put("order_id", Integer.parseInt(order.orderId));
            byte[] payload = body.toString().getBytes(StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(payload.length);
            OutputStream output = connection.getOutputStream();
            output.write(payload);
            output.flush();
            output.close();

            int status = connection.getResponseCode();
            InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
            String response = readAll(stream);
            JSONObject json = response.isEmpty() ? new JSONObject() : new JSONObject(response);
            if (status < 200 || status >= 300 || !json.optBoolean("ok", false)) {
                String message = json.optString("error", "Kapouch вернул HTTP " + status);
                return Result.error(message);
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
        } finally {
            if (connection != null) connection.disconnect();
        }
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
