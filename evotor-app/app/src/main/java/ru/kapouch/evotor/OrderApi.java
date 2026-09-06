package ru.kapouch.evotor;

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
    private OrderApi() {}

    static Result perform(OrderRecord order, String action) {
        if (order == null) return Result.error("Заказ не найден на терминале.");
        if (order.actionUrl == null || !order.actionUrl.startsWith("https://")) return Result.error("В push нет безопасного адреса Kapouch.");
        if (order.actionToken == null || order.actionToken.isEmpty()) return Result.error("Ключ действия заказа отсутствует или уже истёк.");
        if (!"accept".equals(action) && !"ready".equals(action)) return Result.error("Неизвестное действие заказа.");

        HttpURLConnection connection = null;
        try {
            URL url = new URL(order.actionUrl);
            if (!"https".equalsIgnoreCase(url.getProtocol())) return Result.error("Разрешены только HTTPS-запросы к Kapouch.");
            connection = (HttpURLConnection) url.openConnection();
            if (!(connection instanceof HttpsURLConnection)) return Result.error("Kapouch должен быть доступен только по HTTPS.");
            HttpsURLConnection secure = (HttpsURLConnection) connection;
            secure.setSSLSocketFactory(LegacyTls.socketFactory());
            // HostnameVerifier deliberately stays the platform default: the legacy fallback
            // validates a real certificate chain to ISRG Root X1, never all certificates.
            connection.setRequestMethod("POST");
            connection.setConnectTimeout(7000);
            connection.setReadTimeout(10000);
            connection.setDoOutput(true);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Authorization", "Bearer " + order.actionToken);
            connection.setRequestProperty("X-Kapouch-Order-Token", order.actionToken);
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.1.2");

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
            return Result.error(message == null || message.trim().isEmpty() ? "Нет связи с Kapouch." : message);
        } finally {
            if (connection != null) connection.disconnect();
        }
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
