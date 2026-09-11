package ru.kapouch.evotor;

import android.content.Context;
import android.net.SSLCertificateSocketFactory;

import java.io.IOException;
import java.io.InputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.security.KeyStore;
import java.security.cert.CertificateException;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

import javax.net.ssl.SSLSocketFactory;
import javax.net.ssl.TrustManager;
import javax.net.ssl.TrustManagerFactory;
import javax.net.ssl.X509TrustManager;

/**
 * TLS bridge for Evotor OS 4.x / old Android.
 *
 * The important detail here is WHEN SNI is configured. Android's
 * SSLCertificateSocketFactory.createSocket(host, port) performs the TLS
 * handshake (via hostname verification) before returning. Therefore calling
 * setHostname() on that returned socket is too late: the ClientHello has
 * already been sent and Beget can return its no-SNI fallback certificate.
 *
 * This factory always creates an unconnected TLS socket first, sets SNI to
 * kapouch.store, and only then connects. Certificate verification remains
 * strict: Android's normal trust manager is tried first and a narrow fallback
 * accepts only a signature path to official public CA roots bundled in the APK.
 * There is no TrustAll path, no self-signed pin and no hostname-verification
 * bypass.
 */
final class EvotorTls {
    private static final String KAPOUCH_HOST = "kapouch.store";
    private static final String OID_SERVER_AUTH = "1.3.6.1.5.5.7.3.1";
    private static final String OID_ANY_EKU = "2.5.29.37.0";
    private static volatile SSLSocketFactory cached;

    private EvotorTls() {}

    static SSLSocketFactory socketFactory(Context context) throws Exception {
        SSLSocketFactory value = cached;
        if (value != null) return value;
        synchronized (EvotorTls.class) {
            if (cached != null) return cached;

            X509TrustManager system = trustManager(null);
            CertificateFactory certificateFactory = CertificateFactory.getInstance("X.509");
            List<X509Certificate> roots = new ArrayList<>();
            roots.add(load(certificateFactory, context, R.raw.isrg_root_x1));
            roots.add(load(certificateFactory, context, R.raw.isrg_root_x2));
            roots.add(load(certificateFactory, context, R.raw.isrg_root_ye));
            roots.add(load(certificateFactory, context, R.raw.isrg_root_yr));
            roots.add(load(certificateFactory, context, R.raw.digicert_global_root_ca));

            X509TrustManager combined = new CombinedTrustManager(system, roots);
            SSLCertificateSocketFactory platform = new SSLCertificateSocketFactory(10000);
            platform.setTrustManagers(new TrustManager[]{combined});

            cached = new PreHandshakeSniSocketFactory(platform, KAPOUCH_HOST);
            return cached;
        }
    }

    private static X509Certificate load(CertificateFactory factory, Context context, int resourceId) throws Exception {
        InputStream input = context.getResources().openRawResource(resourceId);
        try {
            X509Certificate certificate = (X509Certificate) factory.generateCertificate(input);
            certificate.checkValidity();
            return certificate;
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
     * Configures SNI on an UNCONNECTED socket, before any ClientHello can be
     * emitted. This is the ordering required by SSLCertificateSocketFactory.
     */
    private static final class PreHandshakeSniSocketFactory extends SSLSocketFactory {
        private final SSLCertificateSocketFactory delegate;
        private final String hostname;

        PreHandshakeSniSocketFactory(SSLCertificateSocketFactory delegate, String hostname) {
            this.delegate = delegate;
            this.hostname = hostname;
        }

        private Socket newSocket() throws IOException {
            Socket socket = delegate.createSocket();
            try {
                delegate.setUseSessionTickets(socket, true);
                delegate.setHostname(socket, hostname);
                return socket;
            } catch (RuntimeException e) {
                try { socket.close(); } catch (IOException ignored) {}
                throw new IOException("Unable to configure pre-handshake SNI for " + hostname, e);
            }
        }

        private Socket connect(String host, int port, InetAddress localHost, int localPort) throws IOException {
            Socket socket = newSocket();
            try {
                if (localHost != null || localPort > 0) {
                    socket.bind(new InetSocketAddress(localHost, Math.max(localPort, 0)));
                }
                socket.connect(new InetSocketAddress(host, port));
                return socket;
            } catch (IOException | RuntimeException e) {
                try { socket.close(); } catch (IOException ignored) {}
                throw e;
            }
        }

        private Socket connect(InetAddress address, int port, InetAddress localAddress, int localPort) throws IOException {
            Socket socket = newSocket();
            try {
                if (localAddress != null || localPort > 0) {
                    socket.bind(new InetSocketAddress(localAddress, Math.max(localPort, 0)));
                }
                socket.connect(new InetSocketAddress(address, port));
                return socket;
            } catch (IOException | RuntimeException e) {
                try { socket.close(); } catch (IOException ignored) {}
                throw e;
            }
        }

        @Override public String[] getDefaultCipherSuites() { return delegate.getDefaultCipherSuites(); }
        @Override public String[] getSupportedCipherSuites() { return delegate.getSupportedCipherSuites(); }

        @Override public Socket createSocket() throws IOException {
            return newSocket();
        }

        @Override public Socket createSocket(String host, int port) throws IOException {
            return connect(host, port, null, 0);
        }

        @Override public Socket createSocket(String host, int port, InetAddress localHost, int localPort) throws IOException {
            return connect(host, port, localHost, localPort);
        }

        @Override public Socket createSocket(InetAddress host, int port) throws IOException {
            return connect(host, port, null, 0);
        }

        @Override public Socket createSocket(InetAddress address, int port, InetAddress localAddress, int localPort) throws IOException {
            return connect(address, port, localAddress, localPort);
        }

        @Override public Socket createSocket(Socket plain, String host, int port, boolean autoClose) throws IOException {
            // Android's public SSLCertificateSocketFactory has no API for adding
            // SNI to an already-layered socket before its own verification starts
            // the handshake. On the affected Evotor direct HTTPS path, reconnect
            // with a preconfigured SSL socket instead. Proxy tunnelling is not used
            // by Kapouch and is intentionally not emulated here.
            if (plain != null && autoClose) {
                try { plain.close(); } catch (IOException ignored) {}
            }
            return connect(host, port, null, 0);
        }
    }

    private static final class CombinedTrustManager implements X509TrustManager {
        private final X509TrustManager system;
        private final List<X509Certificate> roots;

        CombinedTrustManager(X509TrustManager system, List<X509Certificate> roots) {
            this.system = system;
            this.roots = roots;
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
                // Old Evotor PKIX can reject otherwise valid modern public chains.
            }

            try {
                verifyPublicChain(chain);
            } catch (CertificateException e) {
                throw new CertificateException(
                        "Kapouch TLS chain rejected: " + describe(chain) + "; " + e.getMessage(), e);
            } catch (Exception e) {
                throw new CertificateException(
                        "Kapouch TLS chain rejected: " + describe(chain) + "; "
                                + e.getClass().getSimpleName() + ": " + e.getMessage(), e);
            }
        }

        private void verifyPublicChain(X509Certificate[] chain) throws Exception {
            if (chain == null || chain.length == 0) throw new CertificateException("empty server chain");
            if (chain.length > 10) throw new CertificateException("server chain is unexpectedly long");

            for (X509Certificate certificate : chain) certificate.checkValidity();
            checkLeafUsage(chain[0]);

            List<X509Certificate> candidates = new ArrayList<>(Arrays.asList(chain));
            Set<String> visited = new HashSet<>();
            if (!walkToTrustedRoot(chain[0], candidates, visited, 0)) {
                throw new CertificateException("no signature path to bundled public CA root");
            }
        }

        private boolean walkToTrustedRoot(
                X509Certificate certificate,
                List<X509Certificate> candidates,
                Set<String> visited,
                int depth) throws Exception {
            if (depth > 10) return false;
            String marker = certificate.getSubjectX500Principal().getName() + "#" + certificate.getSerialNumber();
            if (!visited.add(marker)) return false;

            try {
                for (X509Certificate root : roots) {
                    if (sameIdentity(certificate, root)) {
                        root.checkValidity();
                        return true;
                    }
                    if (certificate.getIssuerX500Principal().equals(root.getSubjectX500Principal())) {
                        root.checkValidity();
                        checkCaUsage(root);
                        certificate.verify(root.getPublicKey());
                        return true;
                    }
                }

                for (X509Certificate issuer : candidates) {
                    if (issuer == certificate) continue;
                    if (!certificate.getIssuerX500Principal().equals(issuer.getSubjectX500Principal())) continue;
                    issuer.checkValidity();
                    checkCaUsage(issuer);
                    try {
                        certificate.verify(issuer.getPublicKey());
                    } catch (Exception ignored) {
                        continue;
                    }
                    if (walkToTrustedRoot(issuer, candidates, visited, depth + 1)) return true;
                }
                return false;
            } finally {
                visited.remove(marker);
            }
        }

        private static boolean sameIdentity(X509Certificate certificate, X509Certificate root) {
            return certificate.getSubjectX500Principal().equals(root.getSubjectX500Principal())
                    && Arrays.equals(certificate.getPublicKey().getEncoded(), root.getPublicKey().getEncoded());
        }

        private static void checkLeafUsage(X509Certificate leaf) throws Exception {
            if (leaf.getBasicConstraints() >= 0) throw new CertificateException("leaf certificate is a CA");

            boolean[] usage = leaf.getKeyUsage();
            if (usage != null) {
                boolean digitalSignature = usage.length > 0 && usage[0];
                boolean keyEncipherment = usage.length > 2 && usage[2];
                boolean keyAgreement = usage.length > 4 && usage[4];
                if (!digitalSignature && !keyEncipherment && !keyAgreement) {
                    throw new CertificateException("leaf keyUsage is not valid for TLS server use");
                }
            }

            List<String> eku = leaf.getExtendedKeyUsage();
            if (eku != null && !eku.contains(OID_SERVER_AUTH) && !eku.contains(OID_ANY_EKU)) {
                throw new CertificateException("leaf EKU does not permit serverAuth");
            }
        }

        private static void checkCaUsage(X509Certificate issuer) throws CertificateException {
            if (issuer.getBasicConstraints() < 0) throw new CertificateException("issuer is not a CA");
            boolean[] usage = issuer.getKeyUsage();
            if (usage != null && (usage.length <= 5 || !usage[5])) {
                throw new CertificateException("issuer keyUsage does not permit certificate signing");
            }
        }

        private static String describe(X509Certificate[] chain) {
            if (chain == null || chain.length == 0) return "<empty>";
            StringBuilder out = new StringBuilder();
            for (int i = 0; i < chain.length; i++) {
                if (i > 0) out.append(" | ");
                X509Certificate certificate = chain[i];
                out.append(shortName(certificate.getSubjectX500Principal().getName()))
                        .append(" -> ")
                        .append(shortName(certificate.getIssuerX500Principal().getName()));
            }
            return out.toString();
        }

        private static String shortName(String name) {
            if (name == null) return "?";
            String[] parts = name.split(",");
            for (String part : parts) {
                String value = part.trim();
                if (value.startsWith("CN=")) return value;
            }
            return name.length() > 80 ? name.substring(0, 80) : name;
        }

        @Override
        public X509Certificate[] getAcceptedIssuers() {
            X509Certificate[] systemIssuers = system.getAcceptedIssuers();
            X509Certificate[] all = new X509Certificate[systemIssuers.length + roots.size()];
            System.arraycopy(systemIssuers, 0, all, 0, systemIssuers.length);
            for (int i = 0; i < roots.size(); i++) all[systemIssuers.length + i] = roots.get(i);
            return all;
        }
    }
}
