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

    // Official self-signed ISRG Root X1 remains the only additional trust anchor.
    // Old Evotor Android builds sometimes fail when a server omits one of the Let's
    // Encrypt intermediates. In that case we complete only the missing Let's Encrypt
    // chain from its signed AIA issuer URLs, verify every signature, and then hand the
    // completed chain back to the normal PKIX validator. Hostname verification is never
    // disabled and the order request itself always remains HTTPS.
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

    private LegacyTls() {}

    static SSLSocketFactory socketFactory() throws Exception {
        SSLSocketFactory value = cachedFactory;
        if (value != null) return value;
        synchronized (LegacyTls.class) {
            if (cachedFactory != null) return cachedFactory;

            X509TrustManager system = trustManager(null);

            CertificateFactory certificateFactory = CertificateFactory.getInstance("X.509");
            X509Certificate root = (X509Certificate) certificateFactory.generateCertificate(
                    new ByteArrayInputStream(ISRG_ROOT_X1.getBytes(StandardCharsets.US_ASCII))
            );
            KeyStore extraStore = KeyStore.getInstance(KeyStore.getDefaultType());
            extraStore.load(null, null);
            extraStore.setCertificateEntry("isrg-root-x1", root);
            X509TrustManager extra = trustManager(extraStore);

            X509TrustManager combined = new CombinedTrustManager(system, extra);
            SSLContext context = SSLContext.getInstance("TLS");
            context.init(null, new TrustManager[]{combined}, new SecureRandom());
            cachedFactory = context.getSocketFactory();
            return cachedFactory;
        }
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
            connection.setRequestProperty("User-Agent", "Kapouch-Orders-Evotor/1.1.2 certificate-chain-helper");
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
            try {
                system.checkServerTrusted(chain, authType);
            } catch (CertificateException systemError) {
                X509Certificate[] completed = completeLetsEncryptChain(chain);
                extra.checkServerTrusted(completed, authType);
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
