package ru.kapouch.evotor;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.security.SecureRandom;
import java.security.cert.CertificateException;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.util.ArrayList;
import java.util.List;

import javax.net.ssl.SSLContext;
import javax.net.ssl.SSLSocketFactory;
import javax.net.ssl.TrustManager;
import javax.net.ssl.TrustManagerFactory;
import javax.net.ssl.X509TrustManager;

/**
 * TLS compatibility layer for old Evotor Android builds.
 *
 * The server is still verified normally and HttpsURLConnection keeps its default
 * hostname verifier. We first use the device trust store. If that fails, we allow
 * a path only through official Let's Encrypt Generation Y intermediates bundled
 * from letsencrypt/website. This avoids depending on an AIA HTTP download on the
 * terminal: kapouch.store has been observed to present only the leaf certificate.
 */
final class KapouchTls {
    private static volatile SSLSocketFactory cachedFactory;
    private static volatile List<X509Certificate> bundledIssuers;

    // Official Let's Encrypt Generation Y intermediates. Source:
    // https://github.com/letsencrypt/website/tree/main/static/certs/gen-y
    private static final String YE1 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIICizCCAhGgAwIBAgIQXd1w3TH4AchcGGp6BLgK/jAKBggqhkjOPQQDAzAuMQsw\n" +
            "CQYDVQQGEwJVUzENMAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZRTAeFw0y\n" +
            "NTA5MDMwMDAwMDBaFw0yODA5MDIyMzU5NTlaMDMxCzAJBgNVBAYTAlVTMRYwFAYD\n" +
            "VQQKEw1MZXQncyBFbmNyeXB0MQwwCgYDVQQDEwNZRTEwdjAQBgcqhkjOPQIBBgUr\n" +
            "gQQAIgNiAAQHZVB1/mimla2hfSurylScjPMZaOJXLz/NnAc2sylm8WDyhU9Ccp+z\n" +
            "ASQi5vSwGGJjSGklkD9fdPR8GpyDIOIjCEfrnbt/v+ZSEPLLEGbaM6EccDbN7p9x\n" +
            "teIm2Avf+ryjge4wgeswDgYDVR0PAQH/BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUF\n" +
            "BwMBMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYDVR0OBBYEFLsgykcL/tflnPmPCSqj\n" +
            "jDdFsbzYMB8GA1UdIwQYMBaAFKPIJlqOoUzQNWP8myPIOq5W809WMDIGCCsGAQUF\n" +
            "BwEBBCYwJDAiBggrBgEFBQcwAoYWaHR0cDovL3llLmkubGVuY3Iub3JnLzATBgNV\n" +
            "HSAEDDAKMAgGBmeBDAECATAnBgNVHR8EIDAeMBygGqAYhhZodHRwOi8veWUuYy5s\n" +
            "ZW5jci5vcmcvMAoGCCqGSM49BAMDA2gAMGUCMQDgjUEahFT/h3DRakqiPZpLvPgf\n" +
            "Zwkt6K2EOMmh1nvEzl83eMLYcod4GCl3b0J1Nn0CMBNYmEQJb4CEG5WoOe7aRn/L\n" +
            "VKu6saHmHEynI7ysIPd8zQsK1HdmhlHKlw9Z5GpGvA==\n" +
            "-----END CERTIFICATE-----\n";

    private static final String YE2 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIICjDCCAhGgAwIBAgIQTfOxXdbAeExQfNN7WObxFTAKBggqhkjOPQQDAzAuMQsw\n" +
            "CQYDVQQGEwJVUzENMAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZRTAeFw0y\n" +
            "NTA5MDMwMDAwMDBaFw0yODA5MDIyMzU5NTlaMDMxCzAJBgNVBAYTAlVTMRYwFAYD\n" +
            "VQQKEw1MZXQncyBFbmNyeXB0MQwwCgYDVQQDEwNZRTIwdjAQBgcqhkjOPQIBBgUr\n" +
            "gQQAIgNiAARxmrQzkdbEEL3MqXt3dJQttYc47axkdDTHud5TPqM2z5uSD5cmk0Wr\n" +
            "HlWXvnlvqBLqiB34kluxIbmMyAiq3/YD6e80/vV259K8XQIdjFXloYOa0mIU71f7\n" +
            "HQ09PvYDlw+jge4wgeswDgYDVR0PAQH/BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUF\n" +
            "BwMBMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYDVR0OBBYEFLlZ8o7PIvCG0zdI/3YU\n" +
            "GLqC2FWHMB8GA1UdIwQYMBaAFKPIJlqOoUzQNWP8myPIOq5W809WMDIGCCsGAQUF\n" +
            "BwEBBCYwJDAiBggrBgEFBQcwAoYWaHR0cDovL3llLmkubGVuY3Iub3JnLzATBgNV\n" +
            "HSAEDDAKMAgGBmeBDAECATAnBgNVHR8EIDAeMBygGqAYhhZodHRwOi8veWUuYy5s\n" +
            "ZW5jci5vcmcvMAoGCCqGSM49BAMDA2kAMGYCMQDIcnw5dcZLN9ffynXnnkLD/itS\n" +
            "JEycJPb3sRkzeqBowup7vOsAwaqoCnNn/jh9wycCMQCJM6CPlaOC4pQYYbJtVPYb\n" +
            "DKrIb2EKk5NpOpE6/XttQYZV/3gilB9l+Cc/DOVwmyg=\n" +
            "-----END CERTIFICATE-----\n";

    private static final String YE3 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIICizCCAhGgAwIBAgIQLTM7c3DEJq+3k6hXVBAwZzAKBggqhkjOPQQDAzAuMQsw\n" +
            "CQYDVQQGEwJVUzENMAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZRTAeFw0y\n" +
            "NTA5MDMwMDAwMDBaFw0yODA5MDIyMzU5NTlaMDMxCzAJBgNVBAYTAlVTMRYwFAYD\n" +
            "VQQKEw1MZXQncyBFbmNyeXB0MQwwCgYDVQQDEwNZRTMwdjAQBgcqhkjOPQIBBgUr\n" +
            "gQQAIgNiAAS7HaCvDM9Y3aYPW+iH6uUTK2KFUYx0vye5xK1KNTWZUgloiW2budqz\n" +
            "vCQaarmJTNujiV+ZrWrOpox+N4kTsiCec6yfw7VGmErLC5ZSq2X4d2vJc1g8VIU3\n" +
            "fb7CuzJ/bDOjge4wgeswDgYDVR0PAQH/BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUF\n" +
            "BwMBMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYDVR0OBBYEFPaiftZeI2/d3UVGYR6C\n" +
            "FXXN8eaOMB8GA1UdIwQYMBaAFKPIJlqOoUzQNWP8myPIOq5W809WMDIGCCsGAQUF\n" +
            "BwEBBCYwJDAiBggrBgEFBQcwAoYWaHR0cDovL3llLmkubGVuY3Iub3JnLzATBgNV\n" +
            "HSAEDDAKMAgGBmeBDAECATAnBgNVHR8EIDAeMBygGqAYhhZodHRwOi8veWUuYy5s\n" +
            "ZW5jci5vcmcvMAoGCCqGSM49BAMDA2gAMGUCMHD5+4lU6i9k7inE4Opkebs+IFjY\n" +
            "jlD9BNaPyQe9/hBSjOOTaF5UZzlNy64JID6VUwIxANr7lTvWWzlGU1PpCR7lfDVX\n" +
            "tmGgr3dPHkigUXaTWbbLrx84pOAdd3FEGJM2Kf5etA==\n" +
            "-----END CERTIFICATE-----\n";

    private static final String YR1 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIE2zCCAsOgAwIBAgIRAKICU/FfJpHAXcHOE7m8yk4wDQYJKoZIhvcNAQELBQAw\n" +
            "LjELMAkGA1UEBhMCVVMxDTALBgNVBAoTBElTUkcxEDAOBgNVBAMTB1Jvb3QgWVIw\n" +
            "HhcNMjUwOTAzMDAwMDAwWhcNMjgwOTAyMjM1OTU5WjAzMQswCQYDVQQGEwJVUzEW\n" +
            "MBQGA1UEChMNTGV0J3MgRW5jcnlwdDEMMAoGA1UEAxMDWVIxMIIBIjANBgkqhkiG\n" +
            "9w0BAQEFAAOCAQ8AMIIBCgKCAQEAoVi8X2xCYgMXvJxNPKp/oF13UMgmPABB07VC\n" +
            "LNDtoXmt9luEZNJSBV10VyT1Pz6LD8Zq1d2gc43WNl1AdRrj4sEnazbOiz0nPpmG\n" +
            "Bp2hui49oZtDIY6wdKeZAi5BbNU20CH6RSBBMLSQ9cXrH8dxdv4PAJ45ssGML68U\n" +
            "SE3BsjC2a6cAN9L5CgXVIQi5tfNiTPoFZZ3S0OlXqLmmtdV95udWAb5b6e/F49Di\n" +
            "CsH0Y00Ag72BVIb1hzynmKe+X0mERBTtsb3BwmpV9ipeBjMLoR/D9cHxHQCWoi5l\n" +
            "TmXwY015J5rGelz1nZjJuxc2kioaX29XJBnhMkP531rSdG5uMwIDAQABo4HuMIHr\n" +
            "MA4GA1UdDwEB/wQEAwIBhjATBgNVHSUEDDAKBggrBgEFBQcDATASBgNVHRMBAf8E\n" +
            "CDAGAQH/AgEAMB0GA1UdDgQWBBQfLzW+RhSCzUCxrnksVXj699Ro+zAfBgNVHSME\n" +
            "GDAWgBTe51tg0CJtQCh9Pw0B/qS1UrRRlDAyBggrBgEFBQcBAQQmMCQwIgYIKwYB\n" +
            "BQUHMAKGFmh0dHA6Ly95ci5pLmxlbmNyLm9yZy8wEwYDVR0gBAwwCjAIBgZngQwB\n" +
            "AgEwJwYDVR0fBCAwHjAcoBqgGIYWaHR0cDovL3lyLmMubGVuY3Iub3JnLzANBgkq\n" +
            "hkiG9w0BAQsFAAOCAgEA0+zvMq3kHig1ddTmmm+RibTr9/RpX7k4buanMMRqbV/y\n" +
            "IvP82zAHN3mvaw+cASuVsdpd0ikjhr4hnhJQLQOzOp2ccKrsdGOAgo0vddeISFAq\n" +
            "EWEV4lmUM3vFF796up+bSgmJ1u6RupDCMxDgF8M3eLvGuj6L0lu3zkQ0KuQLnKxL\n" +
            "tB0oQqn1Idg5CuuGpMvQzk29Pa3D/qHurc0EIM9SxukQuJqq63lxsYyRQFU8yMBO\n" +
            "hq1w5LbfaWNRrz1uklOfI/pYkAb2E2MTZrAMQkBIE2S8Jt1F8gRc96o/xOsrgvSk\n" +
            "a84AisX6xq1lz1Z7jGvrnXc4TMcjxZTjiTaihcYI1JIXZiLtEMSCa5l3cu8YWd6z\n" +
            "dLRQlqRdclVjuQfNHawRJ6GWlkK0QJosivTKwdBw3KxEtzGo8yMHERbsy57gP1UX\n" +
            "HOMcmZYQC0gtyR3SxfenIM/MxC3Ia2Ypab/kQ/CTnlIn2KQ5JUC6NYrGCbhFN9bp\n" +
            "5lKJStEwCUnLpntcrXk5XVDCNv/5RyWpRThkGOV7GetKkQ0qAY8hCzWK6oqnAhDZ\n" +
            "cjlYVdWfqOw3DIOX6EDNBgAqHarRVxyF9QZdOaXSyPJ0ueD2BYJEBgaCGQ8rAaU/\n" +
            "Qc123V5LTXDZW4CcsPBDyhy4v+c8hClAyw/IkJlfBqxB9D+/wvIMHgECZ4ptP6o=\n" +
            "-----END CERTIFICATE-----\n";

    private static final String YR2 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIE2jCCAsKgAwIBAgIQTr0klH4k05SALYSlL9WzGTANBgkqhkiG9w0BAQsFADAu\n" +
            "MQswCQYDVQQGEwJVUzENMAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZUjAe\n" +
            "Fw0yNTA5MDMwMDAwMDBaFw0yODA5MDIyMzU5NTlaMDMxCzAJBgNVBAYTAlVTMRYw\n" +
            "FAYDVQQKEw1MZXQncyBFbmNyeXB0MQwwCgYDVQQDEwNZUjIwggEiMA0GCSqGSIb3\n" +
            "DQEBAQUAA4IBDwAwggEKAoIBAQDZ0LxwBppqh84luqMerV/eeL/fXQ7mLQQv1Lnp\n" +
            "WKZbyvGpx6wh6AfnslAnF6ewTkcHA+gSOoBvm3Dfm06AuGiF+KRut4fAcowqnAQQ\n" +
            "CW98+QPP/eOv/wug7Iyk4NkOxf2I6g2f55T6nJoOTLFcukeRq80JGQEYan+dPFr9\n" +
            "OGUgQK2hGKgNkW87pappsOAuUJcroYhRt5uUis4qaZireiseu32gzDJNBAiKtsvd\n" +
            "6HX4v25bpkRNcS/B/Gtc9kVbUpD+2PLPxdei3Tim55k4tfAEXwD2qyiPTxrTNq6l\n" +
            "N+AMr5g2c1dNqkOTwjxeV6L5lpP1rGiYvLnRaPlOqyZRPW+5AgMBAAGjge4wgesw\n" +
            "DgYDVR0PAQH/BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUFBwMBMBIGA1UdEwEB/wQI\n" +
            "MAYBAf8CAQAwHQYDVR0OBBYEFEAVLSZ57TIgnt+ach3WMh+BDIEMMB8GA1UdIwQY\n" +
            "MBaAFN7nW2DQIm1AKH0/DQH+pLVStFGUMDIGCCsGAQUFBwEBBCYwJDAiBggrBgEF\n" +
            "BQcwAoYWaHR0cDovL3lyLmkubGVuY3Iub3JnLzATBgNVHSAEDDAKMAgGBmeBDAEC\n" +
            "ATAnBgNVHR8EIDAeMBygGqAYhhZodHRwOi8veXIuYy5sZW5jci5vcmcvMA0GCSqG\n" +
            "SIb3DQEBCwUAA4ICAQB0ZUQWZ9/Yn9COEpo+JfecMnB0h0vwDm/M66IqXqw3LoaL\n" +
            "mx9lZvRTeDIS67PUeI3yCA2W6PKRD0/FE/G57lOmS+Xy5AaaL00ICGOqjNcCaMWW\n" +
            "8o8nevHOd4i4lqgtznE/28QwlcdJyF8yBiWHpnyjhEpmNWJURgOCOg2xpwRMBCsj\n" +
            "MScqYPtOhBeuYQvSwAEeTML2Ukh6uGuX4E14q65Ja8cdjF5bAldnP1eE4FBaAwsZ\n" +
            "G2fOqqrKV03Y85Nw2btedP1AtliQuJZs/Jo/gXxXdc7LrH3McgnpnbTiAncX7yES\n" +
            "hP6kzQejllqMCIt52HOjxDGWafS7Xw+DKwqmH+Eqy8dcbOuag/1AYlQoKNVK3F5q\n" +
            "Hh6tEDiMqQcLIibGKteE6iHo4A/bIScbzrhXUYuism42ZYzmc48FMVIH3qy4L84E\n" +
            "TdAH2gtxw0PAhvRVXp8HP7wfngpzsN/8xOTpeRSbM4+Qbc56G6+Bifmv6sk1ieQb\n" +
            "NA3wJdl4DDUuQSV8hBgx6zoI1ZSGORprDFux7c6rhc77QZMSRrEgomBeklervEve\n" +
            "86ylWmZ3WWHV6RLMi8xNvjd71r4EPIGgY7BZU/VPBkq+uA7Gb6mbJnFgV43uh3xy\n" +
            "LRFgxIAphIukwTGSMZZR+AI+Qnp0BYTWovHXozOf3H8r6hozEoT02JHn0AeTfA==\n" +
            "-----END CERTIFICATE-----\n";

    private static final String YR3 =
            "-----BEGIN CERTIFICATE-----\n" +
            "MIIE2jCCAsKgAwIBAgIQdv2+nJw5pmI1PsQaDsOT/jANBgkqhkiG9w0BAQsFADAu\n" +
            "MQswCQYDVQQGEwJVUzENMAsGA1UEChMESVNSRzEQMA4GA1UEAxMHUm9vdCBZUjAe\n" +
            "Fw0yNTA5MDMwMDAwMDBaFw0yODA5MDIyMzU5NTlaMDMxCzAJBgNVBAYTAlVTMRYw\n" +
            "FAYDVQQKEw1MZXQncyBFbmNyeXB0MQwwCgYDVQQDEwNZUjMwggEiMA0GCSqGSIb3\n" +
            "DQEBAQUAA4IBDwAwggEKAoIBAQDJS0+QyfrZm8U0nXugJKg+3nraHoxKN9NOsGvH\n" +
            "T9NtcdgThWuj6gizDMqn9VQilyPJ+qKK7rjgBM/XK3ogx61EPbgQY8LiVNn4nsmR\n" +
            "1UFUdalb/cL/mYtXo3lu3qop7k6Ol+pOLzvlBINhl+Mq7l9VxUCM717UpYumNKxG\n" +
            "NRjALGg5H16C93UQlW8KgRpW58fY5Be3cLqv24bbKRFisb7S/HPA967pW6rAO/DF\n" +
            "FKbi35NfHKEG0jIGqxtsbYbK+/0qe2147p9oUO/SNcuaT9poLFWKmcY90UR0hXKy\n" +
            "+qiR6Kv7/e5gCK/BGkjLXM1AfX1CRDwOiIPPsK+RJOIj00ilAgMBAAGjge4wgesw\n" +
            "DgYDVR0PAQH/BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUFBwMBMBIGA1UdEwEB/wQI\n" +
            "MAYBAf8CAQAwHQYDVR0OBBYEFGllKfkz+Am2QtXh87W1nR9feiJ8MB8GA1UdIwQY\n" +
            "MBaAFN7nW2DQIm1AKH0/DQH+pLVStFGUMDIGCCsGAQUFBwEBBCYwJDAiBggrBgEF\n" +
            "BQcwAoYWaHR0cDovL3lyLmkubGVuY3Iub3JnLzATBgNVHSAEDDAKMAgGBmeBDAEC\n" +
            "ATAnBgNVHR8EIDAeMBygGqAYhhZodHRwOi8veXIuYy5sZW5jci5vcmcvMA0GCSqG\n" +
            "SIb3DQEBCwUAA4ICAQCNj5bxci8knkGgfw3qSv0KbbjZpmKUgZzgYYW6EpiFj4Bx\n" +
            "8CTQ79RCEuitFdGFOvG9xfQdWArQlaO/bsmNcsz0D9wiuwxc9RCo6JC4BKRMcmKE\n" +
            "fRiLzHWwHZfUj0sCAY0yBryOGL80J3sD2C3mjAFwV2mVIeuVKOhQeTcstVceW97+\n" +
            "38AC+juHSq+xu/HuCbX0LgTzMzh8RYy8OtO80IFE2v8qAEDIK2PYoQp27nwftAIC\n" +
            "AtOoGzqHDJqPWeiSTQgt1ndRgGAmV7H0HssX66i8naQFlsiidol4goFYEVMVJArp\n" +
            "g2X5NyumCCX/aSnXKgp8leQ6XoVQz0VI6+k1a0goPUJB7xp67BkCxkwJamDOMpQL\n" +
            "xpdTPI2Z1TO2BuEyzBd8DC/yfm1+rvMCRG3AX44f0ra2ueiM7cdRvzcE+b3c2fun\n" +
            "1n/4xzigiimm8Rzs6Cc0A59mt3XhruOe+zGIXtJgn0wf9XfJhKdKcdEH5VNhFapn\n" +
            "Gc9Tm+fd4n5Qojd7W2CwdBUBgwYIt4Cqxqr2OEf7/k1Xm8eplMQlhwySs/hM0dY6\n" +
            "csOPNIXeJm1YsnJyxUGvKRWjOn+vC9k1SXilnnwhcIs9Pp9e5bckYAnB79VJXN/L\n" +
            "8X8xt/9Qm2xmKKe/xzKA+oRvWKofeFGKU7hcnFbyUwlCu0d+JkgWxQu4x4drcQ==\n" +
            "-----END CERTIFICATE-----\n";

    private KapouchTls() {}

    static SSLSocketFactory socketFactory() throws Exception {
        SSLSocketFactory value = cachedFactory;
        if (value != null) return value;
        synchronized (KapouchTls.class) {
            if (cachedFactory != null) return cachedFactory;
            X509TrustManager system = trustManager(null);
            KeyStore extraStore = KeyStore.getInstance(KeyStore.getDefaultType());
            extraStore.load(null, null);
            List<X509Certificate> issuers = issuers();
            for (int i = 0; i < issuers.size(); i++) extraStore.setCertificateEntry("letsencrypt-gen-y-" + i, issuers.get(i));
            X509TrustManager extra = trustManager(extraStore);
            SSLContext context = SSLContext.getInstance("TLS");
            context.init(null, new TrustManager[]{new Combined(system, extra)}, new SecureRandom());
            cachedFactory = context.getSocketFactory();
            return cachedFactory;
        }
    }

    private static X509TrustManager trustManager(KeyStore store) throws Exception {
        TrustManagerFactory factory = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        factory.init(store);
        for (TrustManager manager : factory.getTrustManagers()) if (manager instanceof X509TrustManager) return (X509TrustManager) manager;
        throw new IllegalStateException("X509TrustManager недоступен");
    }

    private static List<X509Certificate> issuers() throws CertificateException {
        List<X509Certificate> cached = bundledIssuers;
        if (cached != null) return cached;
        synchronized (KapouchTls.class) {
            if (bundledIssuers != null) return bundledIssuers;
            CertificateFactory factory = CertificateFactory.getInstance("X.509");
            String[] pems = new String[]{YE1, YE2, YE3, YR1, YR2, YR3};
            List<X509Certificate> result = new ArrayList<>();
            for (String pem : pems) {
                X509Certificate cert = (X509Certificate) factory.generateCertificate(new ByteArrayInputStream(pem.getBytes(StandardCharsets.US_ASCII)));
                cert.checkValidity();
                result.add(cert);
            }
            bundledIssuers = result;
            return result;
        }
    }

    private static X509Certificate[] complete(X509Certificate[] chain) throws CertificateException {
        if (chain == null || chain.length == 0) return chain;
        X509Certificate leaf = chain[0];
        for (X509Certificate issuer : issuers()) {
            if (!leaf.getIssuerX500Principal().equals(issuer.getSubjectX500Principal())) continue;
            try {
                leaf.verify(issuer.getPublicKey());
                X509Certificate[] completed = new X509Certificate[chain.length + 1];
                System.arraycopy(chain, 0, completed, 0, chain.length);
                completed[chain.length] = issuer;
                return completed;
            } catch (Exception ignored) {
            }
        }
        return chain;
    }

    private static String describe(X509Certificate[] chain) {
        if (chain == null || chain.length == 0) return "empty";
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < chain.length; i++) {
            if (i > 0) out.append(" -> ");
            X509Certificate c = chain[i];
            out.append(c.getSubjectX500Principal().getName());
            out.append(" [issuer=").append(c.getIssuerX500Principal().getName()).append(']');
        }
        return out.toString();
    }

    private static final class Combined implements X509TrustManager {
        private final X509TrustManager system;
        private final X509TrustManager extra;

        Combined(X509TrustManager system, X509TrustManager extra) {
            this.system = system;
            this.extra = extra;
        }

        @Override public void checkClientTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            system.checkClientTrusted(chain, authType);
        }

        @Override public void checkServerTrusted(X509Certificate[] chain, String authType) throws CertificateException {
            Throwable first = null;
            try {
                system.checkServerTrusted(chain, authType);
                return;
            } catch (CertificateException | RuntimeException e) {
                first = e;
            }
            X509Certificate[] completed = chain;
            try {
                completed = complete(chain);
                extra.checkServerTrusted(completed, authType);
                return;
            } catch (CertificateException | RuntimeException e) {
                CertificateException failure = new CertificateException("Kapouch TLS not trusted. chain=" + describe(completed), e);
                if (first != null) failure.addSuppressed(first);
                throw failure;
            }
        }

        @Override public X509Certificate[] getAcceptedIssuers() {
            X509Certificate[] a = system.getAcceptedIssuers();
            X509Certificate[] b = extra.getAcceptedIssuers();
            X509Certificate[] all = new X509Certificate[a.length + b.length];
            System.arraycopy(a, 0, all, 0, a.length);
            System.arraycopy(b, 0, all, a.length, b.length);
            return all;
        }
    }
}
