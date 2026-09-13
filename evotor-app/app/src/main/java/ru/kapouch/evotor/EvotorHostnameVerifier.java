package ru.kapouch.evotor;

import java.security.MessageDigest;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.util.Collection;
import java.util.List;

import javax.net.ssl.HostnameVerifier;
import javax.net.ssl.HttpsURLConnection;
import javax.net.ssl.SSLSession;

/**
 * Hostname compatibility bridge for the physical Evotor OS 4.x terminal.
 *
 * Normal certificates are always handled by Android's default hostname verifier.
 * The hosting edge seen by this old terminal can instead return a reviewed,
 * self-signed CN=kapouch.store certificate with no subjectAltName extension.
 * Modern hostname verification correctly rejects such a certificate even though
 * the CN matches, so we permit one very narrow fallback only when all of these
 * conditions hold:
 *   - the requested host is exactly kapouch.store;
 *   - the peer chain is exactly one certificate;
 *   - the certificate is current, self-issued and correctly self-signed;
 *   - subjectAltName is absent/empty (the exact legacy failure mode);
 *   - the subject CN is exactly kapouch.store;
 *   - the SPKI SHA-256 is one of the explicitly reviewed physical-terminal pins.
 *
 * This is not a permissive hostname verifier and does not accept arbitrary CNs
 * or arbitrary self-signed certificates.
 */
final class EvotorHostnameVerifier implements HostnameVerifier {
    static final EvotorHostnameVerifier INSTANCE = new EvotorHostnameVerifier();

    private static final String KAPOUCH_HOST = "kapouch.store";
    private static final HostnameVerifier DEFAULT = HttpsURLConnection.getDefaultHostnameVerifier();

    private static final byte[] REVIEWED_SPKI_A = hex(
            "4ee80751bcff28354f7fe5541bc1351a62ea4d280d9090acf1df25f1d2068989");
    private static final byte[] REVIEWED_SPKI_B = hex(
            "f36dc1fef61b95e380fff55a283bd471af8e4eee5b614f66ea51a49a8812973b");

    private EvotorHostnameVerifier() {}

    @Override
    public boolean verify(String hostname, SSLSession session) {
        if (DEFAULT.verify(hostname, session)) return true;
        if (!KAPOUCH_HOST.equalsIgnoreCase(hostname) || session == null) return false;

        try {
            Certificate[] peer = session.getPeerCertificates();
            if (peer == null || peer.length != 1 || !(peer[0] instanceof X509Certificate)) return false;

            X509Certificate leaf = (X509Certificate) peer[0];
            leaf.checkValidity();
            if (leaf.getBasicConstraints() >= 0) return false;
            if (!leaf.getSubjectX500Principal().equals(leaf.getIssuerX500Principal())) return false;
            leaf.verify(leaf.getPublicKey());

            Collection<List<?>> subjectAltNames = leaf.getSubjectAlternativeNames();
            if (subjectAltNames != null && !subjectAltNames.isEmpty()) return false;
            if (!hasExactCommonName(leaf, KAPOUCH_HOST)) return false;

            byte[] observed = MessageDigest.getInstance("SHA-256")
                    .digest(leaf.getPublicKey().getEncoded());
            return MessageDigest.isEqual(REVIEWED_SPKI_A, observed)
                    || MessageDigest.isEqual(REVIEWED_SPKI_B, observed);
        } catch (Exception ignored) {
            return false;
        }
    }

    private static boolean hasExactCommonName(X509Certificate certificate, String expected) {
        String distinguishedName = certificate.getSubjectX500Principal().getName();
        if (distinguishedName == null || distinguishedName.isEmpty()) return false;
        String[] parts = distinguishedName.split(",");
        for (String part : parts) {
            String value = part.trim();
            if (value.regionMatches(true, 0, "CN=", 0, 3)) {
                return expected.equalsIgnoreCase(value.substring(3).trim());
            }
        }
        return false;
    }

    private static byte[] hex(String value) {
        if (value == null || value.length() != 64) {
            throw new IllegalArgumentException("Expected 32-byte SHA-256 hex value");
        }
        byte[] out = new byte[32];
        for (int i = 0; i < out.length; i++) {
            int hi = Character.digit(value.charAt(i * 2), 16);
            int lo = Character.digit(value.charAt(i * 2 + 1), 16);
            if (hi < 0 || lo < 0) throw new IllegalArgumentException("Invalid SHA-256 hex value");
            out[i] = (byte) ((hi << 4) | lo);
        }
        return out;
    }
}
