package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;
import android.util.Base64;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.IOException;
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
    private static final String BRIDGE_URL = "https://kapouch.store/evotor-bridge?type=loyalty";
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

        try {
            URL url = new URL(lookupUrl == null || lookupUrl.isEmpty() ? DEFAULT_LOOKUP_URL : lookupUrl);
            if (!allowedLookupUrl(url)) {
                url = new URL(DEFAULT_LOOKUP_URL);
                prefs.edit().putString(KEY_LOOKUP_URL, DEFAULT_LOOKUP_URL).apply();
            }

            Response response;
            boolean bridgeUsed = false;
            String primaryTransportError = "";
            try {
                response = postLookup(context, url, code, terminalToken, bootstrapToken);
            } catch (IOException primary) {
                // Proxy v2 on older Evotor firmware can terminate the intercepted
                // POST while Android is writing its JSON body. Use the same
                // cookie-only GET bridge as the HTTP 405 fallback so the retry has
                // no request body and no custom X-* transport headers.
                bridgeUsed = true;
                primaryTransportError = transportMessage(primary);
                try {
                    response = bridgeLookup(context, code, terminalToken, bootstrapToken);
                } catch (Exception bridge) {
                    return Result.error("POST оборвался: " + primaryTransportError
                            + "; bridge: " + transportMessage(bridge));
                }
            }

            if (!bridgeUsed && response.status == 405) {
                bridgeUsed = true;
                response = bridgeLookup(context, code, terminalToken, bootstrapToken);
            }

            JSONObject json = response.body.isEmpty() ? new JSONObject() : new JSONObject(response.body);
            if (response.status < 200 || response.status >= 300 || !json.optBoolean("ok", false)) {
                String fallback = "Kapouch вернул HTTP " + response.status;
                if (bridgeUsed) fallback = bridgeFailure(response, primaryTransportError);
                return Result.error(json.optString("error", fallback));
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
            return Result.error("Evotor HTTPS " + transportMessage(e));
        }
    }

    private static Response postLookup(Context context, URL url, String code, String terminalToken, String bootstrapToken) throws Exception {
        HttpsURLConnection connection = null;
        try {
            connection = openApi(context, url);
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            if (terminalToken != null && !terminalToken.isEmpty()) {
                connection.setRequestProperty("X-Kapouch-Terminal-Token", terminalToken);
            }

            JSONObject body = new JSONObject();
            body.put("code", code);
            if (bootstrapToken != null && !bootstrapToken.isEmpty()) body.put("bootstrap_order_token", bootstrapToken);
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

    private static Response bridgeLookup(Context context, String code, String terminalToken, String bootstrapToken) throws Exception {
        HttpsURLConnection connection = null;
        try {
            connection = openBridge(context, new URL(BRIDGE_URL));
            connection.setRequestMethod("GET");
            connection.setDoOutput(false);

            JSONObject payload = new JSONObject();
            payload.put("type", "loyalty");
            payload.put("code", code);
            if (terminalToken != null && !terminalToken.isEmpty()) payload.put("terminal_token", terminalToken);
            if (bootstrapToken != null && !bootstrapToken.isEmpty()) payload.put("bootstrap_token", bootstrapToken);
            connection.setRequestProperty("Cookie", "kapouch_evotor=" + bridgeCookie(payload));
            return response(connection);
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private static HttpsURLConnection openApi(Context context, URL url) throws Exception {
        HttpsURLConnection connection = openBase(context, url);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Cache-Control", "no-store");
        connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.24");
        return connection;
    }

    private static HttpsURLConnection openBridge(Context context, URL url) throws Exception {
        HttpsURLConnection connection = openBase(context, url);
        connection.setRequestProperty("Accept", "*/*");
        connection.setRequestProperty("User-Agent", "Mozilla/5.0");
        return connection;
    }

    private static HttpsURLConnection openBase(Context context, URL url) throws Exception {
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
        return connection;
    }

    private static String bridgeCookie(JSONObject payload) {
        byte[] bytes = payload.toString().getBytes(StandardCharsets.UTF_8);
        return Base64.encodeToString(bytes, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
    }

    private static Response response(HttpURLConnection connection) throws Exception {
        int status = connection.getResponseCode();
        InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
        return new Response(
                status,
                readAll(stream),
                connection.getHeaderField("Server"),
                connection.getHeaderField("Allow"),
                connection.getHeaderField("Content-Type"));
    }

    private static String bridgeFailure(Response response, String primaryTransportError) {
        StringBuilder out = new StringBuilder("Kapouch bridge вернул HTTP ").append(response.status).append(" (cookie-only)");
        if (primaryTransportError != null && !primaryTransportError.isEmpty()) {
            out.append("; POST=").append(primaryTransportError);
        }
        if (!response.allow.isEmpty()) out.append("; Allow=").append(response.allow);
        if (!response.server.isEmpty()) out.append("; Server=").append(response.server);
        if (!response.contentType.isEmpty()) out.append("; Type=").append(response.contentType);
        String snippet = response.body == null ? "" : response.body.replace('\n', ' ').replace('\r', ' ').trim();
        if (!snippet.isEmpty()) {
            if (snippet.length() > 120) snippet = snippet.substring(0, 120);
            out.append("; body=").append(snippet);
        }
        return out.toString();
    }

    private static String transportMessage(Throwable error) {
        if (error == null) return "неизвестная ошибка";
        String message = error.getMessage();
        String type = error.getClass().getSimpleName();
        if (message == null || message.trim().isEmpty()) return type;
        return type + ": " + message.trim();
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
        StringBuilder result = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) result.append(line);
        reader.close();
        return result.toString();
    }

    private static final class Response {
        final int status;
        final String body;
        final String server;
        final String allow;
        final String contentType;

        Response(int status, String body, String server, String allow, String contentType) {
            this.status = status;
            this.body = body == null ? "" : body;
            this.server = server == null ? "" : server;
            this.allow = allow == null ? "" : allow;
            this.contentType = contentType == null ? "" : contentType;
        }
    }

    static final class Result {
        final boolean ok;final String name;final double balance;final boolean linked;final String error;
        final boolean drinkProgramEnabled;final int drinkProgress;final int drinkRequired;final int availableRewards;final double giftCap;
        private Result(boolean ok,String name,double balance,boolean linked,String error,boolean drinkProgramEnabled,int drinkProgress,int drinkRequired,int availableRewards,double giftCap){this.ok=ok;this.name=name;this.balance=balance;this.linked=linked;this.error=error;this.drinkProgramEnabled=drinkProgramEnabled;this.drinkProgress=drinkProgress;this.drinkRequired=drinkRequired;this.availableRewards=availableRewards;this.giftCap=giftCap;}
        static Result success(String name,double balance,boolean linked,boolean drinkProgramEnabled,int drinkProgress,int drinkRequired,int availableRewards,double giftCap){return new Result(true,name,balance,linked,"",drinkProgramEnabled,drinkProgress,drinkRequired,availableRewards,giftCap);}
        static Result error(String error){return new Result(false,"",0d,false,error==null?"Ошибка":error,false,0,5,0,0d);}
    }
}
