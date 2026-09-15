package ru.kapouch.evotor;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

import javax.net.ssl.HttpsURLConnection;

import ru.evotor.framework.receipt.Position;
import ru.evotor.framework.receipt.Receipt;

final class LoyaltyDiscountApi {
    private static final String URL = "https://kapouch.store/api/evotor_loyalty_discount.php";

    private LoyaltyDiscountApi() {}

    static Quote quote(Context context, String code, Receipt receipt) {
        if (context == null || receipt == null || code == null || !code.startsWith(CustomerScanReceiver.KAPOUCH_PREFIX)) {
            return Quote.none();
        }
        try {
            JSONObject body = new JSONObject();
            body.put("action", "quote");
            body.put("code", code);
            body.put("receipt_uuid", receipt.getHeader().getUuid());
            JSONArray positions = new JSONArray();
            for (Position position : receipt.getPositions()) {
                if (position == null || position.getQuantity() == null) continue;
                JSONObject item = new JSONObject();
                item.put("uuid", position.getUuid());
                item.put("product_uuid", position.getProductUuid());
                item.put("name", position.getName());
                item.put("price", position.getPriceWithDiscountPosition() == null ? 0d : position.getPriceWithDiscountPosition().doubleValue());
                item.put("quantity", position.getQuantity().doubleValue());
                positions.put(item);
            }
            body.put("positions", positions);
            JSONObject json = request(context, body);
            if (!json.optBoolean("ok", false)) return Quote.error(json.optString("error", "Не удалось рассчитать подарок."));
            return new Quote(
                    true,
                    Math.max(0d, json.optDouble("discount", 0d)),
                    json.optBoolean("gift", false),
                    json.optBoolean("already_applied", false),
                    json.optBoolean("same_order_unlock", false),
                    json.optString("product_name", ""),
                    "");
        } catch (Exception e) {
            return Quote.error(e.getClass().getSimpleName() + (e.getMessage() == null ? "" : ": " + e.getMessage()));
        }
    }

    static void confirm(Context context, String receiptUuid) {
        if (context == null || receiptUuid == null || receiptUuid.trim().isEmpty()) return;
        try {
            JSONObject body = new JSONObject();
            body.put("action", "confirm");
            body.put("receipt_uuid", receiptUuid.trim());
            request(context, body);
        } catch (Exception ignored) {
        }
    }

    private static JSONObject request(Context context, JSONObject body) throws Exception {
        SharedPreferences prefs = context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        String terminalToken = prefs.getString(LoyaltyApi.KEY_TERMINAL_TOKEN, "");
        if (terminalToken == null || terminalToken.isEmpty()) throw new IllegalStateException("Терминал Kapouch не авторизован");
        HttpsURLConnection connection = null;
        try {
            HttpURLConnection raw = (HttpURLConnection) new URL(URL).openConnection();
            if (!(raw instanceof HttpsURLConnection)) throw new IllegalStateException("Kapouch API требует HTTPS");
            connection = (HttpsURLConnection) raw;
            connection.setSSLSocketFactory(EvotorTls.socketFactory(context.getApplicationContext()));
            connection.setHostnameVerifier(EvotorHostnameVerifier.INSTANCE);
            connection.setConnectTimeout(7000);
            connection.setReadTimeout(10000);
            connection.setUseCaches(false);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Cache-Control", "no-store");
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.26");
            connection.setRequestProperty("X-Kapouch-Terminal-Token", terminalToken);
            byte[] payload = body.toString().getBytes(StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(payload.length);
            OutputStream output = connection.getOutputStream();
            output.write(payload);output.flush();output.close();
            int status = connection.getResponseCode();
            InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
            String response = readAll(stream);
            JSONObject json = response.isEmpty() ? new JSONObject() : new JSONObject(response);
            if (status < 200 || status >= 300) throw new IllegalStateException(json.optString("error", "HTTP " + status));
            return json;
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private static String readAll(InputStream stream) throws Exception {
        if (stream == null) return "";
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder out = new StringBuilder();String line;
        while ((line = reader.readLine()) != null) out.append(line);
        reader.close();return out.toString();
    }

    static final class Quote {
        final boolean ok;final double discount;final boolean gift;final boolean alreadyApplied;final boolean sameOrderUnlock;final String productName;final String error;
        Quote(boolean ok,double discount,boolean gift,boolean alreadyApplied,boolean sameOrderUnlock,String productName,String error){this.ok=ok;this.discount=discount;this.gift=gift;this.alreadyApplied=alreadyApplied;this.sameOrderUnlock=sameOrderUnlock;this.productName=productName;this.error=error;}
        static Quote none(){return new Quote(true,0d,false,false,false,"","");}
        static Quote error(String error){return new Quote(false,0d,false,false,false,"",error==null?"Ошибка":error);}
    }
}
