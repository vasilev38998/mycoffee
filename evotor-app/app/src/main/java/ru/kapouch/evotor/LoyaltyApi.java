package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

import javax.net.ssl.HttpsURLConnection;

final class LoyaltyApi {
    private static final String DEFAULT_LOOKUP_URL = "https://kapouch.store/api/evotor_customer_lookup.php";

    private LoyaltyApi() {}

    static Result lookup(Context context, String code) {
        if (context == null || code == null || !code.startsWith("KAPOUCH:LOYALTY:")) {
            return Result.error("Это не QR-карта Kapouch.");
        }
        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        String lookupUrl = prefs.getString(MainActivity.KEY_LOYALTY_LOOKUP_URL, DEFAULT_LOOKUP_URL);
        String terminalToken = prefs.getString(MainActivity.KEY_LOYALTY_TERMINAL_TOKEN, "");
        String bootstrapToken = prefs.getString(MainActivity.KEY_LOYALTY_BOOTSTRAP_TOKEN, "");
        HttpURLConnection connection = null;
        try {
            URL url = new URL(lookupUrl == null || lookupUrl.isEmpty() ? DEFAULT_LOOKUP_URL : lookupUrl);
            if (!"https".equalsIgnoreCase(url.getProtocol())) return Result.error("Адрес лояльности Kapouch должен быть HTTPS.");
            connection = (HttpURLConnection) url.openConnection();
            if (!(connection instanceof HttpsURLConnection)) return Result.error("Kapouch должен быть доступен по HTTPS.");
            HttpsURLConnection secure = (HttpsURLConnection) connection;
            secure.setSSLSocketFactory(LegacyTls.socketFactory());
            connection.setRequestMethod("POST");
            connection.setConnectTimeout(7000);
            connection.setReadTimeout(10000);
            connection.setDoOutput(true);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.0");
            if (terminalToken != null && !terminalToken.isEmpty()) connection.setRequestProperty("X-Kapouch-Terminal-Token", terminalToken);

            JSONObject body = new JSONObject();
            body.put("code", code);
            if ((terminalToken == null || terminalToken.isEmpty()) && bootstrapToken != null && !bootstrapToken.isEmpty()) {
                body.put("bootstrap_order_token", bootstrapToken);
            }
            byte[] payload = body.toString().getBytes(StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(payload.length);
            OutputStream output = connection.getOutputStream();
            output.write(payload);output.flush();output.close();

            int status = connection.getResponseCode();
            InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
            String response = readAll(stream);
            JSONObject json = response.isEmpty() ? new JSONObject() : new JSONObject(response);
            if (status < 200 || status >= 300 || !json.optBoolean("ok", false)) {
                return Result.error(json.optString("error", "Kapouch вернул HTTP " + status));
            }
            String issuedToken = json.optString("terminal_token", "");
            if (!issuedToken.isEmpty()) prefs.edit().putString(MainActivity.KEY_LOYALTY_TERMINAL_TOKEN, issuedToken).apply();
            JSONObject customer = json.optJSONObject("customer");
            JSONObject loyalty = json.optJSONObject("loyalty");
            JSONObject link = json.optJSONObject("link");
            if (customer == null) return Result.error("Kapouch не вернул профиль клиента.");
            String name = customer.optString("name", "").trim();
            if (name.isEmpty()) name = "Клиент Kapouch";
            double balance = loyalty != null ? loyalty.optDouble("balance", customer.optDouble("loyalty_balance", 0d)) : customer.optDouble("loyalty_balance", 0d);
            boolean linked = link != null && link.optBoolean("active", false);
            return Result.success(name, balance, linked);
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
        StringBuilder result = new StringBuilder();String line;
        while ((line = reader.readLine()) != null) result.append(line);
        reader.close();return result.toString();
    }

    static final class Result {
        final boolean ok;final String name;final double balance;final boolean linked;final String error;
        private Result(boolean ok,String name,double balance,boolean linked,String error){this.ok=ok;this.name=name;this.balance=balance;this.linked=linked;this.error=error;}
        static Result success(String name,double balance,boolean linked){return new Result(true,name,balance,linked,"");}
        static Result error(String error){return new Result(false,"",0d,false,error==null?"Ошибка":error);}
    }
}
