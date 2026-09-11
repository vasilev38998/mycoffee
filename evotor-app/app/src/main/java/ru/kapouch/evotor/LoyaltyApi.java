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
    static final String KEY_LOOKUP_URL = "loyalty_lookup_url";
    static final String KEY_TERMINAL_TOKEN = "loyalty_terminal_token";
    static final String KEY_BOOTSTRAP_ORDER_TOKEN = "loyalty_bootstrap_order_token";
    private static final String DEFAULT_LOOKUP_URL = "https://kapouch.store/api/evotor_customer_lookup.php";
    private static final String KAPOUCH_HOST = "kapouch.store";
    private static final String LOOKUP_PATH = "/api/evotor_customer_lookup.php";

    private LoyaltyApi() {}

    static Result lookup(Context context, String code) {
        if (context == null || code == null || !code.startsWith("KAPOUCH:LOYALTY:")) {
            return Result.error("Это не QR-карта Kapouch.");
        }
        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        String lookupUrl = prefs.getString(KEY_LOOKUP_URL, DEFAULT_LOOKUP_URL);
        String terminalToken = prefs.getString(KEY_TERMINAL_TOKEN, "");
        String bootstrapToken = prefs.getString(KEY_BOOTSTRAP_ORDER_TOKEN, "");
        HttpURLConnection connection = null;
        try {
            URL url = new URL(lookupUrl == null || lookupUrl.isEmpty() ? DEFAULT_LOOKUP_URL : lookupUrl);
            if (!allowedLookupUrl(url)) {
                url = new URL(DEFAULT_LOOKUP_URL);
                prefs.edit().putString(KEY_LOOKUP_URL, DEFAULT_LOOKUP_URL).apply();
            }
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
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.9");
            if (terminalToken != null && !terminalToken.isEmpty()) connection.setRequestProperty("X-Kapouch-Terminal-Token", terminalToken);

            JSONObject body = new JSONObject();
            body.put("code", code);
            if (bootstrapToken != null && !bootstrapToken.isEmpty()) body.put("bootstrap_order_token", bootstrapToken);
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
            if (!issuedToken.isEmpty()) prefs.edit().putString(KEY_TERMINAL_TOKEN, issuedToken).apply();
            JSONObject customer = json.optJSONObject("customer");
            JSONObject loyalty = json.optJSONObject("loyalty");
            JSONObject link = json.optJSONObject("link");
            JSONObject drinks = json.optJSONObject("drink_loyalty");
            if (customer == null) return Result.error("Kapouch не вернул профиль клиента.");
            String name = customer.optString("name", "").trim();
            if (name.isEmpty()) name = "Клиент Kapouch";
            double balance = loyalty != null ? loyalty.optDouble("balance", customer.optDouble("loyalty_balance", 0d)) : customer.optDouble("loyalty_balance", 0d);
            boolean linked = link != null && link.optBoolean("active", false);
            boolean drinkEnabled = drinks != null && drinks.optBoolean("enabled", false);
            int progress = drinks != null ? drinks.optInt("progress", 0) : 0;
            int required = drinks != null ? Math.max(1, drinks.optInt("required_paid", 5)) : 5;
            int availableRewards = drinks != null ? Math.max(0, drinks.optInt("available_rewards", 0)) : 0;
            double giftCap = drinks != null ? Math.max(0d, drinks.optDouble("gift_cap", 0d)) : 0d;
            return Result.success(name, balance, linked, drinkEnabled, progress, required, availableRewards, giftCap);
        } catch (Exception e) {
            String message = e.getMessage();
            String type = e.getClass().getSimpleName();
            if (message == null || message.trim().isEmpty()) message = "Нет связи с Kapouch.";
            return Result.error("Evotor HTTPS " + type + ": " + message);
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    static String lookupUrlFromActionUrl(String actionUrl) {
        try {
            URL action = new URL(actionUrl == null ? "" : actionUrl.trim());
            if (!OrderApi.allowedActionUrl(action)) return DEFAULT_LOOKUP_URL;
        } catch (Exception ignored) {
            return DEFAULT_LOOKUP_URL;
        }
        return DEFAULT_LOOKUP_URL;
    }

    static boolean allowedLookupUrl(URL url) {
        if (url == null || !"https".equalsIgnoreCase(url.getProtocol())) return false;
        if (!KAPOUCH_HOST.equalsIgnoreCase(url.getHost())) return false;
        int port = url.getPort();
        if (port != -1 && port != 443) return false;
        return LOOKUP_PATH.equals(url.getPath()) && (url.getUserInfo() == null || url.getUserInfo().isEmpty());
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
        final boolean drinkProgramEnabled;final int drinkProgress;final int drinkRequired;final int availableRewards;final double giftCap;
        private Result(boolean ok,String name,double balance,boolean linked,String error,boolean drinkProgramEnabled,int drinkProgress,int drinkRequired,int availableRewards,double giftCap){this.ok=ok;this.name=name;this.balance=balance;this.linked=linked;this.error=error;this.drinkProgramEnabled=drinkProgramEnabled;this.drinkProgress=drinkProgress;this.drinkRequired=drinkRequired;this.availableRewards=availableRewards;this.giftCap=giftCap;}
        static Result success(String name,double balance,boolean linked,boolean drinkProgramEnabled,int drinkProgress,int drinkRequired,int availableRewards,double giftCap){return new Result(true,name,balance,linked,"",drinkProgramEnabled,drinkProgress,drinkRequired,availableRewards,giftCap);}
        static Result error(String error){return new Result(false,"",0d,false,error==null?"Ошибка":error,false,0,5,0,0d);}
    }
}
