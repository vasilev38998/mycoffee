package ru.kapouch.evotor;

import android.content.Context;
import android.net.SSLCertificateSocketFactory;

import java.io.IOException;
import java.io.InputStream;
import java.net.InetAddress;
import java.net.Socket;
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
 * Two old-platform problems have to be handled explicitly:
 *  1) the Java trust store can be older than the browser trust store;
 *  2) HttpsURLConnection on the affected Evotor does not reliably configure
 *     SNI when a custom socket factory is installed.
 *
 * We therefore keep Android's SSLCertificateSocketFactory, extend its trust
 * manager only with official CA roots, and wrap every created socket to force
 * SNI=kapouch.store before the TLS handshake. Hostname verification remains the
 * platform default in HttpsURLConnection; no TrustAll/hostname bypass is used.
 */
final class EvotorTls {
    private static final String KAPOUCH_HOST = "kapouch.store";
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
            add(extraStore, certificateFactory, context, "digicert-global-root-ca", R.raw.digicert_global_root_ca);
            X509TrustManager extra = trustManager(extraStore);

            X509TrustManager combined = new CombinedTrustManager(system, extra);

            SSLCertificateSocketFactory platform = new SSLCertificateSocketFactory(10000);
            platform.setTrustManagers(new TrustManager[]{combined});

            // Do not rely on HttpsURLConnection to propagate SNI through a custom
            // factory on Evotor OS 4.x. The physical terminal otherwise receives
            // Beget's fallback self-signed CN=kapouch.store certificate.
            cached = new FixedSniSocketFactory(platform, KAPOUCH_HOST);
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

    /**
     * A host-pinned wrapper is intentional: OrderApi and LoyaltyApi already
     * reject every host except kapouch.store, so even createSocket() overloads
     * that do not receive a hostname can still set the correct SNI value.
     */
    private static final class FixedSniSocketFactory extends SSLSocketFactory {
        private final SSLCertificateSocketFactory delegate;
        private final String hostname;

        FixedSniSocketFactory(SSLCertificateSocketFactory delegate, String hostname) {
            this.delegate = delegate;
            this.hostname = hostname;
        }

        private Socket configure(Socket socket) throws IOException {
            try {
                delegate.setUseSessionTickets(socket, true);
                delegate.setHostname(socket, hostname);
                return socket;
            } catch (RuntimeException e) {
                try { socket.close(); } catch (IOException ignored) {}
                throw new IOException("Unable to configure SNI for " + hostname, e);
            }
        }

        @Override public String[] getDefaultCipherSuites() { return delegate.getDefaultCipherSuites(); }
        @Override public String[] getSupportedCipherSuites() { return delegate.getSupportedCipherSuites(); }

        @Override public Socket createSocket() throws IOException {
            return configure(delegate.createSocket());
        }

        @Override public Socket createSocket(String host, int port) throws IOException {
            return configure(delegate.createSocket(host, port));
        }

        @Override public Socket createSocket(String host, int port, InetAddress localHost, int localPort) throws IOException {
            return configure(delegate.createSocket(host, port, localHost, localPort));
        }

        @Override public Socket createSocket(InetAddress host, int port) throws IOException {
            return configure(delegate.createSocket(host, port));
        }

        @Override public Socket createSocket(InetAddress address, int port, InetAddress localAddress, int localPort) throws IOException {
            return configure(delegate.createSocket(address, port, localAddress, localPort));
        }

        @Override public Socket createSocket(Socket socket, String host, int port, boolean autoClose) throws IOException {
            return configure(delegate.createSocket(socket, host, port, autoClose));
        }
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
                // Old Evotor CA store may not contain the current public root.
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
