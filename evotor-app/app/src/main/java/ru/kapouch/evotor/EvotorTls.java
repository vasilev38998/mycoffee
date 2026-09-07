package ru.kapouch.evotor;

import android.content.Context;
import android.net.SSLCertificateSocketFactory;

import java.io.InputStream;
import java.security.KeyStore;
import java.security.cert.CertificateException;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;

import javax.net.ssl.SSLSocketFactory;
import javax.net.ssl.TrustManager;
import javax.net.ssl.TrustManagerFactory;
import javax.net.ssl.X509TrustManager;

/**
 * TLS bridge for Evotor OS 4.x / old Android.
 *
 * The terminal's Chromium browser has a newer CA set than the Android Java
 * trust store used by HttpsURLConnection. We keep Android's own
 * SSLCertificateSocketFactory (important for SNI on old Evotor builds) and
 * extend only its TrustManager with official ISRG / Let's Encrypt roots.
 * Hostname verification remains enabled by HttpsURLConnection.
 */
final class EvotorTls {
    private static volatile SSLSocketFactory cached;

    private EvotorTls() {}

    static SSLSocketFactory socketFactory(Context context) throws Exception {
        SSLSocketFactory value = cached;
        if (value != null) return value;
        synchronized (EvotorTls.class) {
            if (cached != null) return cached;

            X509TrustManager system = trustManager(null);

            KeyStore extraStore = KeyStore.getInstance(KeyStore.getDefaultType());
            extraStore.load(null, null);
            CertificateFactory certificateFactory = CertificateFactory.getInstance("X.509");
            add(extraStore, certificateFactory, context, "isrg-root-x1", R.raw.isrg_root_x1);
            add(extraStore, certificateFactory, context, "isrg-root-x2", R.raw.isrg_root_x2);
            add(extraStore, certificateFactory, context, "isrg-root-ye", R.raw.isrg_root_ye);
            add(extraStore, certificateFactory, context, "isrg-root-yr", R.raw.isrg_root_yr);
            X509TrustManager extra = trustManager(extraStore);

            X509TrustManager combined = new CombinedTrustManager(system, extra);

            // Android-specific factory is intentional. Unlike a plain
            // SSLContext.getSocketFactory() on the affected Evotor, this keeps
            // the platform's legacy SNI behavior while allowing our CA roots.
            SSLCertificateSocketFactory factory = new SSLCertificateSocketFactory(10000);
            factory.setTrustManagers(new TrustManager[]{combined});
            cached = factory;
            return cached;
        }
    }

    private static void add(KeyStore store, CertificateFactory factory, Context context, String alias, int resourceId) throws Exception {
        InputStream input = context.getResources().openRawResource(resourceId);
        try {
            X509Certificate certificate = (X509Certificate) factory.generateCertificate(input);
            certificate.checkValidity();
            store.setCertificateEntry(alias, certificate);
        } finally {
            input.close();
        }
    }

    private static X509TrustManager trustManager(KeyStore keyStore) throws Exception {
        TrustManagerFactory factory = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        factory.init(keyStore);
        for (TrustManager manager : factory.getTrustManagers()) {
            if (manager instanceof X509TrustManager) return (X509TrustManager) manager;
        }
        throw new IllegalStateException("X509TrustManager unavailable");
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
                return;
            } catch (CertificateException | RuntimeException ignored) {
                // Old Evotor CA store may not contain current ISRG roots.
            }
            extra.checkServerTrusted(chain, authType);
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
