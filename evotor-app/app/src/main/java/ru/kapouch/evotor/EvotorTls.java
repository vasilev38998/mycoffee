package ru.kapouch.evotor;

import android.content.Context;
import android.net.SSLCertificateSocketFactory;
import android.util.Base64;

import java.io.IOException;
import java.io.InputStream;
import java.net.InetAddress;
import java.net.Socket;
import java.security.KeyStore;
import java.security.MessageDigest;
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
 * Verification order is deliberately strict:
 *  1) Android's normal system trust manager;
 *  2) a manual path to one of the official public CA roots bundled in the APK;
 *  3) one legacy hosting fallback key observed on physical Evotor OS 4.x when
 *     the terminal fails to send effective SNI and Beget serves its self-signed
 *     kapouch.store certificate.
 *
 * The legacy fallback is an SPKI pin, not TrustAll. The peer must present one
 * current, self-signed certificate whose public key exactly matches the known
 * Beget fallback key. HttpsURLConnection still performs its normal hostname
 * verification afterwards, so the certificate must also identify kapouch.store.
 */
final class EvotorTls {
    private static final String KAPOUCH_HOST = "kapouch.store";
    private static final String OID_SERVER_AUTH = "1.3.6.1.5.5.7.3.1";
    private static final String OID_ANY_EKU = "2.5.29.37.0";

    // Captured by CI from `openssl s_client -noservername` and independently
    // reproduced by the physical Evotor. If Beget rotates this key, CI fails
    // before release and this pin must be reviewed instead of silently widened.
    private static final String LEGACY_NO_SNI_SPKI_SHA256 =
            "TugHUbz/KDVPf+VUG8E1GmLqTSgNkJCs8d8l8dIGiYk=";

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

            // Keep forcing SNI for terminals on which it works. The pinned
            // fallback below exists only for the physical OS 4.x path where
            // this call still results in the provider's no-SNI certificate.
            cached = new FixedSniSocketFactory(platform, KAPOUCH_HOST);
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
     * A host-pinned wrapper is intentional: OrderApi and LoyaltyApi reject every
     * host except kapouch.store, so createSocket() overloads which do not receive
     * a hostname can still set the correct SNI value.
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
                // Continue with narrowly scoped compatibility checks.
            }

            Exception publicChainError;
            try {
                verifyPinnedPublicChain(chain);
                return;
            } catch (Exception e) {
                publicChainError = e;
            }

            Exception legacyPinError;
            try {
                verifyLegacyNoSniLeaf(chain);
                return;
            } catch (Exception e) {
                legacyPinError = e;
            }

            throw new CertificateException(
                    "Kapouch TLS chain rejected: " + describe(chain)
                            + "; public=" + safeMessage(publicChainError)
                            + "; legacy-pin=" + safeMessage(legacyPinError),
                    legacyPinError);
        }

        private void verifyPinnedPublicChain(X509Certificate[] chain) throws Exception {
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

        private static void verifyLegacyNoSniLeaf(X509Certificate[] chain) throws Exception {
            if (chain == null || chain.length != 1) {
                throw new CertificateException("expected one self-signed fallback certificate");
            }
            X509Certificate leaf = chain[0];
            leaf.checkValidity();
            checkLeafUsage(leaf);
            if (!leaf.getSubjectX500Principal().equals(leaf.getIssuerX500Principal())) {
                throw new CertificateException("fallback certificate is not self-issued");
            }
            try {
                leaf.verify(leaf.getPublicKey());
            } catch (Exception e) {
                throw new CertificateException("fallback certificate self-signature is invalid", e);
            }

            String observed = spkiSha256(leaf);
            if (!LEGACY_NO_SNI_SPKI_SHA256.equals(observed)) {
                throw new CertificateException("fallback SPKI mismatch: " + observed);
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

        private static String spkiSha256(X509Certificate certificate) throws Exception {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] value = digest.digest(certificate.getPublicKey().getEncoded());
            return Base64.encodeToString(value, Base64.NO_WRAP);
        }

        private static String safeMessage(Exception error) {
            if (error == null) return "unknown";
            String message = error.getMessage();
            if (message == null || message.trim().isEmpty()) return error.getClass().getSimpleName();
            return message;
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
