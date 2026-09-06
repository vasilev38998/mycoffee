package ru.kapouch.evotor;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.security.SecureRandom;
import java.security.cert.CertificateException;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import javax.net.ssl.SSLContext;
import javax.net.ssl.SSLSocketFactory;
import javax.net.ssl.TrustManager;
import javax.net.ssl.TrustManagerFactory;
import javax.net.ssl.X509TrustManager;

final class LegacyTls {
    private static volatile SSLSocketFactory cachedFactory;
    private static final Object ISSUER_CACHE_LOCK = new Object();
    private static final Map<String, X509Certificate> ISSUER_CACHE = new HashMap<>();
    private static final int MAX_CHAIN_LENGTH = 8;
    private static final int MAX_ISSUER_BYTES = 64 * 1024;

    // Old Evotor Android builds predate the current Let's Encrypt hierarchy. The platform
    // trust manager is always tried first. The compatibility fallback trusts only official
    // ISRG/Let's Encrypt roots and, when needed, completes missing issuers from restricted
    // *.i.lencr.org AIA URLs. Every downloaded issuer is signature-checked before the
    // completed chain is handed to the normal PKIX validator. Hostname verification stays
    // the HttpsURLConnection platform default and the order request itself remains HTTPS.
    private static final String ISRG_ROOT_X1 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw\n" +
            "TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh\n" +
            "cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4\n" +
            "WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu\n" +
            "ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY\n" +
            "MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc\n" +
            "h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+\n" +
            "0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U\n" +
            "A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW\n" +
            "T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH\n" +
            "B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC\n" +
            "B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv\n" +
            "KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn\n" +
            "OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn\n" +
            "jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw\n" +
            "qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI\n" +
            "rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV\n" +
            "HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq\n" +
            "hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL\n" +
            "ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ\n" +
            "3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK\n" +
            "NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5\n" +
            "ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur\n" +
            "TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC\n" +
            "jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc\n" +
            "oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq\n" +
            "4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA\n" +
            "mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d\n" +
            "emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=\n" +
            "-----END CERTIFICATE-----\n";

    private static final String ISRG_ROOT_X2 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIICGzCCAaGgAwIBAgIQQdKd0XLq7qeAwSxs6S+HUjAKBggqhkjOPQQDAzBPMQsw\n" +
            "CQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJuZXQgU2VjdXJpdHkgUmVzZWFyY2gg\n" +
            "R3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBYMjAeFw0yMDA5MDQwMDAwMDBaFw00\n" +
            "MDA5MTcxNjAwMDBaME8xCzAJBgNVBAYTAlVTMSkwJwYDVQQKEyBJbnRlcm5ldCBT\n" +
            "ZWN1cml0eSBSZXNlYXJjaCBHcm91cDEVMBMGA1UEAxMMSVNSRyBSb290IFgyMHYw\n" +
            "EAYHKoZIzj0CAQYFK4EEACIDYgAEzZvVn4CDCuwJSvMWSj5cz3es3mcFDR0HttwW\n" +
            "+1qLFNvicWDEukWVEYmO6gbf9yoWHKS5xcUy4APgHoIYOIvXRdgKam7mAHf7AlF9\n" +
            "ItgKbppbd9/w+kHsOdx1ymgHDB/qo0IwQDAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0T\n" +
            "AQH/BAUwAwEB/zAdBgNVHQ4EFgQUfEKWrt5LSDv6kviejM9ti6lyN5UwCgYIKoZI\n" +
            "zj0EAwMDaAAwZQIwe3lORlCEwkSHRhtFcP9Ymd70/aTSVaYgLXTWNLxBo1BfASdW\n" +
            "tL4ndQavEi51mI38AjEAi/V3bNTIZargCyzuFJ0nN6T5U6VR5CmD1/iQMVtCnwr1\n" +
            "/q4AaOeMSQ+2b1tbFfLn\n" +
            "-----END CERTIFICATE-----\n";

    private static final String ROOT_YE =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIB2TCCAWCgAwIBAgIRAKQCa6LvbHwg1AR+XmWmk4AwCgYIKoZIzj0EAwMwLjEL\n" +
            "MAkGA1UEBhMCVVMxDTALBgNVBAoTBElTUkcxEDAOBgNVBAMTB1Jvb3QgWUUwHhcN\n" +
            "MjUwOTAzMDAwMDAwWhcNNDUwOTAyMjM1OTU5WjAuMQswCQYDVQQGEwJVUzENMAsG\n" +
            "A1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZRTB2MBAGByqGSM49AgEGBSuBBAAi\n" +
            "A2IABDwS/6vhrcVqcbBo+wgdI3fwn9x7DNJJOY/lTOti0vkwuRN87RhEhTH17E7X\n" +
            "yFjWsPYhIPt/wzOqxTd2b+4ZJNy9ID04YywF9U5zasDVyGSNErVNtz8uSGh5izW8\n" +
            "7j77GaNCMEAwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQFMAMBAf8wHQYDVR0O\n" +
            "BBYEFKPIJlqOoUzQNWP8myPIOq5W809WMAoGCCqGSM49BAMDA2cAMGQCMHhMr8N9\n" +
            "LdL1VQKs9BdV81r76eXRB6mtjuNjzk6/lBsPNToWLTDzGYgtQKO1jl63uAIwGV7m\n" +
            "onyF377c+MM1oqVNs17sgu7F9YKZwgLmVbeOMDbKAXHtKMDLbiGllCcs8f47\n" +
            "-----END CERTIFICATE-----\n";

    private static final String ROOT_YR =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIFKTCCAxGgAwIBAgIRAOxGNJNgz0sP+KmC2Tqpyj0wDQYJKoZIhvcNAQELBQAw\n" +
            "LjELMAkGA1UEBhMCVVMxDTALBgNVBAoTBElTUkcxEDAOBgNVBAMTB1Jvb3QgWVIw\n" +
            "HhcNMjUwOTAzMDAwMDAwWhcNNDUwOTAyMjM1OTU5WjAuMQswCQYDVQQGEwJVUzEN\n" +
            "MAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZUjCCAiIwDQYJKoZIhvcNAQEB\n" +
            "BQADggIPADCCAgoCggIBANvGJnN78CTJdWL3+eGfsLN5TrNBJs+VH9hRXqRbwxu9\n" +
            "sGNiB0BD1fcOxbSUQCJIM1xE13Db+5Cw1w0s0EBYsvuIP/6joF0w8cuImbgR1OGg\n" +
            "YbSQ4OpzI+DG8SGuTlcE873OCS+kh3srlo6vl43M5OJg4Aeo1sfHp6kTJDoIiFBN\n" +
            "JAY+OKfX/FUvYKuhjT+no49lmqmupSBI5PkBQiqrEGtWU5uxU/cQWHGu8jSjFBzn\n" +
            "ZqvbNPLMXMLFxCb3WTfrJBXXjqvWG+v4bjzxjjeAtOlU7qarRDvNOyAuQYLln904\n" +
            "M+faKx8hnLCpJ15ZqaEgcNlY+9MMWcC5yvL2A2j3l9+2buggZX+dOE91zYmIdawT\n" +
            "vSZuVvlbRrAlLxIB6pwMBjneXCjYQ8+3BCCjssbSNpZU3hTcBDdhfAlEDlYr6pEa\n" +
            "tnMdmDT5BqnKC92bd0EhM1fbLHioLccLCuievT8ZkPhZrq7Mii7gNXAcUEAR8+lz\n" +
            "Yal+9zTg7C5DALyVOeG/CqfRAMn1KSHCR0NSA6P8tn/mGRlnCct5rtVCLnVySVpU\n" +
            "6H1qGg3DgTOuskf8eahTMiYbI5ezPJmO5ertalskQ1utp74+eDy92PI4ftHKTbq9\n" +
            "IWhH4YZKh3WnJEIt+oQvlYZbY8tpEroKrFB6PFGzrJIDRyts4HqvuH52RFj2zv/B\n" +
            "AgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTADAQH/MB0GA1Ud\n" +
            "DgQWBBTe51tg0CJtQCh9Pw0B/qS1UrRRlDANBgkqhkiG9w0BAQsFAAOCAgEAWHnf\n" +
            "713Bdkq7t5yN2dNIgQakUb94X9WuyhMEHHkgx4oDpSUlnG0w4g94MoqaEUE31ZjR\n" +
            "LU7L5LD1g9ujFHTQu8AD215AHMVQFbm6j8hQxdXHAzDajFNQnOlDJrLjzIx176oy\n" +
            "AjvUtejZx2NNmdb5fd0WGVGsCdoAJ3N8ozo7ajE8t6vfxStZb4BQ9WYJGHUDrv2N\n" +
            "i5tJF6CNiPnlzs3BUfECRbE4JSk+jvy8+VoGiFE8qsH/j78x2fjgQhAQFV7P7Zxy\n" +
            "dBTZ1wEkNpZNW2qnaK1SKBLa+xf6E06YRIq5uaI+HWH8SY1y5VbRgzq40EKg3yxP\n" +
            "06fz+uYAUIFJoLNfhwRCc3Q6pQVuMX3yAjHAes4gk4moGcLQ5p7HAh39yeylZc1J\n" +
            "41sx/jKwLIkPE6Rr1Nf4pxdsxf9SA4yOEiAkDgq04DVxn8hgYFdUtBCuiuVC2heA\n" +
            "EiqVEa+8QZjuw8Gj0EbHXcRd1nInvGqRS1o9Is7YBdQN57X1AYveGBNNqjICSb7c\n" +
            "awuw1EawTDrs13VUlJVEsbQ0/O/1aaV73mCdOQ8azqL2KTv1Ewu1xbquE2S+kdQU\n" +
            "To9TUwat3wUA6cwXh1EfpS/3fJ0aGah5hdpRyoCLDlsSn8tkrjMfFFX0viC+GxHc\n" +
            "sI1ANRYvqSFC2X1VRZfDg+wD6E21BccmifG4yWc=\n" +
            "-----END CERTIFICATE-----\n";

    private LegacyTls() {}

    static SSLSocketFactory socketFactory() throws Exception {
        SSLSocketFactory value = cachedFactory;
        if (value != null) return value;
        synchronized (LegacyTls.class) {
            if (cachedFactory != null) return cachedFactory;

            X509TrustManager system = trustManager(null);
            KeyStore extraStore = KeyStore.getInstance(KeyStore.getDefaultType());
            extraStore.load(null, null);
            addCertificate(extraStore, "isrg-root-x1", ISRG_ROOT_X1);
            addCertificate(extraStore, "isrg-root-x2", ISRG_ROOT_X2);
            addCertificate(extraStore, "isrg-root-ye", ROOT_YE);
            addCertificate(extraStore, "isrg-root-yr", ROOT_YR);
            X509TrustManager extra = trustManager(extraStore);

            X509TrustManager combined = new CombinedTrustManager(system, extra);
            SSLContext context = SSLContext.getInstance("TLS");
            context.init(null, new TrustManager[]{combined}, new SecureRandom());
            cachedFactory = context.getSocketFactory();
            return cachedFactory;
        }
    }

    private static void addCertificate(KeyStore store, String alias, String pem) throws Exception {
        CertificateFactory factory = CertificateFactory.getInstance("X.509");
        X509Certificate certificate = (X509Certificate) factory.generateCertificate(
                new ByteArrayInputStream(pem.getBytes(StandardCharsets.US_ASCII))
        );
        certificate.checkValidity();
        store.setCertificateEntry(alias, certificate);
    }

    private static X509TrustManager trustManager(KeyStore store) throws Exception {
        TrustManagerFactory factory = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        factory.init(store);
        for (TrustManager manager : factory.getTrustManagers()) {
            if (manager instanceof X509TrustManager) return (X509TrustManager) manager;
        }
        throw new IllegalStateException("X509TrustManager недоступен");
    }

    private static X509Certificate[] completeLetsEncryptChain(X509Certificate[] presented) throws CertificateException {
        if (presented == null || presented.length == 0) return presented;

        List<X509Certificate> result = new ArrayList<>();
        result.add(presented[0]);
        Set<String> used = new HashSet<>();
        used.add(certificateKey(presented[0]));

        while (result.size() < MAX_CHAIN_LENGTH) {
            X509Certificate child = result.get(result.size() - 1);
            if (isSelfSigned(child)) break;

            X509Certificate issuer = findPresentedIssuer(child, presented, used);
            if (issuer == null) issuer = fetchLetsEncryptIssuer(child);
            if (issuer == null) break;

            String issuerKey = certificateKey(issuer);
            if (!used.add(issuerKey)) break;
            verifyIssuer(child, issuer);
            result.add(issuer);
        }
        return result.toArray(new X509Certificate[result.size()]);
    }

    private static X509Certificate findPresentedIssuer(X509Certificate child, X509Certificate[] presented, Set<String> used) {
        for (X509Certificate candidate : presented) {
            if (candidate == null || used.contains(certificateKey(candidate))) continue;
            if (!child.getIssuerX500Principal().equals(candidate.getSubjectX500Principal())) continue;
            try {
                verifyIssuer(child, candidate);
                return candidate;
            } catch (CertificateException ignored) {
            }
        }
        return null;
    }

    private static X509Certificate fetchLetsEncryptIssuer(X509Certificate child) throws CertificateException {
        String issuerUrl = letsEncryptIssuerUrl(child);
        if (issuerUrl == null) return null;

        synchronized (ISSUER_CACHE_LOCK) {
            X509Certificate cached = ISSUER_CACHE.get(issuerUrl);
            if (cached != null) {
                verifyIssuer(child, cached);
                return cached;
            }
        }

        HttpURLConnection connection = null;
        try {
            URL url = new URL(issuerUrl);
            if (!isAllowedLetsEncryptIssuerUrl(url)) return null;
            connection = (HttpURLConnection) url.openConnection();
            connection.setConnectTimeout(2500);
            connection.setReadTimeout(2500);
            connection.setInstanceFollowRedirects(false);
            connection.setUseCaches(true);
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.2.1 certificate-chain-helper");
            int status = connection.getResponseCode();
            if (status != HttpURLConnection.HTTP_OK) return null;

            byte[] encoded = readLimited(connection.getInputStream(), MAX_ISSUER_BYTES);
            CertificateFactory factory = CertificateFactory.getInstance("X.509");
            X509Certificate issuer = (X509Certificate) factory.generateCertificate(new ByteArrayInputStream(encoded));
            verifyIssuer(child, issuer);
            synchronized (ISSUER_CACHE_LOCK) {
                ISSUER_CACHE.put(issuerUrl, issuer);
            }
            return issuer;
        } catch (Exception ignored) {
            return null;
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private static void verifyIssuer(X509Certificate child, X509Certificate issuer) throws CertificateException {
        if (!child.getIssuerX500Principal().equals(issuer.getSubjectX500Principal())) {
            throw new CertificateException("TLS issuer subject mismatch");
        }
        try {
            child.verify(issuer.getPublicKey());
        } catch (Exception e) {
            throw new CertificateException("TLS issuer signature mismatch", e);
        }
    }

    private static String letsEncryptIssuerUrl(X509Certificate certificate) {
        byte[] aia = certificate.getExtensionValue("1.3.6.1.5.5.7.1.1");
        if (aia == null || aia.length == 0) return null;
        byte[] prefix = "http://".getBytes(StandardCharsets.US_ASCII);
        for (int i = 0; i <= aia.length - prefix.length; i++) {
            boolean matches = true;
            for (int j = 0; j < prefix.length; j++) {
                if (aia[i + j] != prefix[j]) {
                    matches = false;
                    break;
                }
            }
            if (!matches) continue;
            int end = i + prefix.length;
            while (end < aia.length) {
                int value = aia[end] & 0xff;
                if (value < 0x21 || value > 0x7e) break;
                end++;
            }
            try {
                URL candidate = new URL(new String(aia, i, end - i, StandardCharsets.US_ASCII));
                if (isAllowedLetsEncryptIssuerUrl(candidate)) return candidate.toString();
            } catch (Exception ignored) {
            }
        }
        return null;
    }

    private static boolean isAllowedLetsEncryptIssuerUrl(URL url) {
        if (url == null || !"http".equalsIgnoreCase(url.getProtocol())) return false;
        int port = url.getPort();
        if (port != -1 && port != 80) return false;
        String host = url.getHost() == null ? "" : url.getHost().toLowerCase(Locale.US);
        return host.endsWith(".i.lencr.org") && host.length() > ".i.lencr.org".length();
    }

    private static byte[] readLimited(InputStream input, int maxBytes) throws Exception {
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        byte[] buffer = new byte[4096];
        int total = 0;
        int read;
        while ((read = input.read(buffer)) != -1) {
            total += read;
            if (total > maxBytes) throw new CertificateException("TLS issuer certificate is too large");
            output.write(buffer, 0, read);
        }
        input.close();
        return output.toByteArray();
    }

    private static boolean isSelfSigned(X509Certificate certificate) {
        if (!certificate.getSubjectX500Principal().equals(certificate.getIssuerX500Principal())) return false;
        try {
            certificate.verify(certificate.getPublicKey());
            return true;
        } catch (Exception ignored) {
            return false;
        }
    }

    private static String certificateKey(X509Certificate certificate) {
        return certificate.getSubjectX500Principal().getName() + "#" + certificate.getSerialNumber().toString(16);
    }

    private static String chainSummary(X509Certificate[] chain) {
        if (chain == null || chain.length == 0) return "empty";
        StringBuilder result = new StringBuilder();
        for (int i = 0; i < chain.length; i++) {
            if (i > 0) result.append(" -> ");
            X509Certificate certificate = chain[i];
            result.append(certificate.getSubjectX500Principal().getName());
        }
        return result.toString();
    }

    private static final class CombinedTrustManager implements X509TrustManager {
        private final X509TrustManager system;
        private final X509TrustManager extra;

        CombinedTrustManager(X509TrustManager system, X509TrustManager extra) {
            this.system = system;
            this.extra = extra;
        }

        @Override
        public void checkClientTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            system.checkClientTrusted(chain, authType);
        }

        @Override
        public void checkServerTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            Throwable systemError = null;
            try {
                system.checkServerTrusted(chain, authType);
                return;
            } catch (CertificateException | RuntimeException error) {
                systemError = error;
            }

            X509Certificate[] completed = chain;
            try {
                completed = completeLetsEncryptChain(chain);
                extra.checkServerTrusted(completed, authType);
            } catch (CertificateException | RuntimeException fallbackError) {
                CertificateException error = new CertificateException(
                        "TLS chain not trusted. received=" + chainSummary(chain) + "; completed=" + chainSummary(completed),
                        fallbackError
                );
                if (systemError != null) error.addSuppressed(systemError);
                throw error;
            }
        }

        @Override
        public X509Certificate[] getAcceptedIssuers() {
            X509Certificate[] a = system.getAcceptedIssuers();
            X509Certificate[] b = extra.getAcceptedIssuers();
            X509Certificate[] all = new X509Certificate[a.length + b.length];
            System.arraycopy(a, 0, all, 0, a.length);
            System.arraycopy(b, 0, all, a.length, b.length);
            return all;
        }
    }
}
