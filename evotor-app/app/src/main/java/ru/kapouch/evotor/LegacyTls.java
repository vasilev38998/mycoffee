package ru.kapouch.evotor;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.security.cert.CertificateException;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.util.Arrays;
import java.util.List;

import javax.net.ssl.SSLContext;
import javax.net.ssl.SSLSocketFactory;
import javax.net.ssl.TrustManager;
import javax.net.ssl.TrustManagerFactory;
import javax.net.ssl.X509TrustManager;

final class LegacyTls {
    private static volatile SSLSocketFactory cachedFactory;
    private static final String SERVER_AUTH_OID = "1.3.6.1.5.5.7.3.1";

    // Official self-signed ISRG Root X1. kapouch.store currently serves:
    // leaf -> Let's Encrypt YR1 -> ISRG Root YR -> ISRG Root X1.
    // Some old Evotor Android builds fail to construct this modern cross-signed path even
    // when X1 is supplied as a normal TrustManager anchor. We therefore keep the system
    // trust manager first and use a strict signature/path fallback to this exact public CA.
    // Hostname verification remains the platform default in HttpsURLConnection.
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

            X509TrustManager system = trustManager();
            X509Certificate root = parseCertificate(ISRG_ROOT_X1);
            X509TrustManager combined = new CombinedTrustManager(system, root);
            SSLContext context = SSLContext.getInstance("TLS");
            context.init(null, new TrustManager[]{combined}, new SecureRandom());
            cachedFactory = context.getSocketFactory();
            return cachedFactory;
        }
    }

    private static X509Certificate parseCertificate(String pem) throws Exception {
        CertificateFactory factory = CertificateFactory.getInstance("X.509");
        return (X509Certificate) factory.generateCertificate(
                new ByteArrayInputStream(pem.getBytes(StandardCharsets.US_ASCII))
        );
    }

    private static X509TrustManager trustManager() throws Exception {
        TrustManagerFactory factory = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        factory.init((java.security.KeyStore) null);
        for (TrustManager manager : factory.getTrustManagers()) {
            if (manager instanceof X509TrustManager) return (X509TrustManager) manager;
        }
        throw new IllegalStateException("X509TrustManager недоступен");
    }

    private static final class CombinedTrustManager implements X509TrustManager {
        private final X509TrustManager system;
        private final X509Certificate legacyRoot;

        CombinedTrustManager(X509TrustManager system, X509Certificate legacyRoot) {
            this.system = system;
            this.legacyRoot = legacyRoot;
        }

        @Override
        public void checkClientTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            system.checkClientTrusted(chain, authType);
        }

        @Override
        public void checkServerTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            try {
                system.checkServerTrusted(chain, authType);
                return;
            } catch (CertificateException systemError) {
                try {
                    verifyLegacyServerChain(chain, legacyRoot);
                    return;
                } catch (Exception legacyError) {
                    CertificateException error = new CertificateException(
                            "Сертификат Kapouch не прошёл проверку цепочки доверия.", legacyError
                    );
                    error.addSuppressed(systemError);
                    throw error;
                }
            }
        }

        @Override
        public X509Certificate[] getAcceptedIssuers() {
            X509Certificate[] systemIssuers = system.getAcceptedIssuers();
            X509Certificate[] all = Arrays.copyOf(systemIssuers, systemIssuers.length + 1);
            all[systemIssuers.length] = legacyRoot;
            return all;
        }
    }

    static void verifyLegacyServerChain(X509Certificate[] chain, X509Certificate trustedRoot) throws Exception {
        if (chain == null || chain.length == 0 || chain.length > 8) {
            throw new CertificateException("Некорректная TLS-цепочка.");
        }
        trustedRoot.checkValidity();

        X509Certificate leaf = chain[0];
        if (leaf.getBasicConstraints() >= 0) throw new CertificateException("Серверный сертификат не должен быть CA.");
        leaf.checkValidity();
        List<String> eku = leaf.getExtendedKeyUsage();
        if (eku != null && !eku.contains(SERVER_AUTH_OID)) {
            throw new CertificateException("Сертификат не разрешён для TLS-сервера.");
        }

        for (int i = 0; i < chain.length; i++) {
            X509Certificate cert = chain[i];
            cert.checkValidity();
            if (i > 0) verifyCa(cert);
            if (i + 1 < chain.length) {
                X509Certificate issuer = chain[i + 1];
                if (!cert.getIssuerX500Principal().equals(issuer.getSubjectX500Principal())) {
                    throw new CertificateException("Нарушен порядок сертификатов в TLS-цепочке.");
                }
                cert.verify(issuer.getPublicKey());
            }
        }

        X509Certificate last = chain[chain.length - 1];
        if (Arrays.equals(last.getEncoded(), trustedRoot.getEncoded())) return;
        if (!last.getIssuerX500Principal().equals(trustedRoot.getSubjectX500Principal())) {
            throw new CertificateException("TLS-цепочка не заканчивается доверенным ISRG Root X1.");
        }
        last.verify(trustedRoot.getPublicKey());
    }

    private static void verifyCa(X509Certificate certificate) throws CertificateException {
        if (certificate.getBasicConstraints() < 0) {
            throw new CertificateException("Промежуточный сертификат не является CA.");
        }
        boolean[] usage = certificate.getKeyUsage();
        if (usage != null && (usage.length <= 5 || !usage[5])) {
            throw new CertificateException("CA-сертификат не разрешён для подписи сертификатов.");
        }
    }
}
