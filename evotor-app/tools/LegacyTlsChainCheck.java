package ru.kapouch.evotor;

import java.io.FileInputStream;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;

public final class LegacyTlsChainCheck {
    public static void main(String[] args) throws Exception {
        if (args.length < 1) throw new IllegalArgumentException("certificate files required");
        CertificateFactory factory = CertificateFactory.getInstance("X.509");
        X509Certificate[] chain = new X509Certificate[args.length];
        for (int i = 0; i < args.length; i++) {
            FileInputStream input = new FileInputStream(args[i]);
            try {
                chain[i] = (X509Certificate) factory.generateCertificate(input);
            } finally {
                input.close();
            }
        }
        X509Certificate root = (X509Certificate) factory.generateCertificate(
                new java.io.ByteArrayInputStream(LegacyTls.rootPemForTest().getBytes("US-ASCII"))
        );
        LegacyTls.verifyLegacyServerChain(chain, root);
        System.out.println("LEGACY TLS CHAIN PASSED: " + chain[0].getSubjectX500Principal());
    }
}
