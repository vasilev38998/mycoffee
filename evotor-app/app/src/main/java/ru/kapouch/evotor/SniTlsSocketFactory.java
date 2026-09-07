package ru.kapouch.evotor;

import java.io.IOException;
import java.lang.reflect.Constructor;
import java.lang.reflect.Method;
import java.net.InetAddress;
import java.net.Socket;
import java.util.Collections;
import java.util.List;

import javax.net.ssl.SSLParameters;
import javax.net.ssl.SSLSocket;
import javax.net.ssl.SSLSocketFactory;

/**
 * Host-bound TLS socket factory for old Evotor Android builds.
 *
 * Some legacy Android/Conscrypt combinations lose SNI when HttpsURLConnection
 * is given a custom SSLContext socket factory. On shared hosting that can make
 * the server return a fallback/self-signed virtual-host certificate even though
 * the requested HTTPS hostname has a valid Let's Encrypt certificate.
 *
 * This wrapper keeps KapouchTls certificate validation intact and only forces
 * the expected SNI hostname before the TLS handshake. Hostname verification is
 * still performed by HttpsURLConnection's normal verifier.
 */
final class SniTlsSocketFactory extends SSLSocketFactory {
    private final SSLSocketFactory delegate;
    private final String expectedHost;

    private SniTlsSocketFactory(SSLSocketFactory delegate, String expectedHost) {
        if (delegate == null) throw new IllegalArgumentException("TLS factory is required");
        if (expectedHost == null || expectedHost.trim().isEmpty()) throw new IllegalArgumentException("TLS host is required");
        this.delegate = delegate;
        this.expectedHost = expectedHost.trim();
    }

    static SSLSocketFactory forHost(SSLSocketFactory delegate, String expectedHost) {
        return new SniTlsSocketFactory(delegate, expectedHost);
    }

    @Override
    public String[] getDefaultCipherSuites() {
        return delegate.getDefaultCipherSuites();
    }

    @Override
    public String[] getSupportedCipherSuites() {
        return delegate.getSupportedCipherSuites();
    }

    @Override
    public Socket createSocket() throws IOException {
        return configure(delegate.createSocket(), expectedHost);
    }

    @Override
    public Socket createSocket(String host, int port) throws IOException {
        return configure(delegate.createSocket(host, port), host);
    }

    @Override
    public Socket createSocket(String host, int port, InetAddress localHost, int localPort) throws IOException {
        return configure(delegate.createSocket(host, port, localHost, localPort), host);
    }

    @Override
    public Socket createSocket(InetAddress host, int port) throws IOException {
        return configure(delegate.createSocket(host, port), expectedHost);
    }

    @Override
    public Socket createSocket(InetAddress address, int port, InetAddress localAddress, int localPort) throws IOException {
        return configure(delegate.createSocket(address, port, localAddress, localPort), expectedHost);
    }

    @Override
    public Socket createSocket(Socket socket, String host, int port, boolean autoClose) throws IOException {
        return configure(delegate.createSocket(socket, host, port, autoClose), host);
    }

    private Socket configure(Socket socket, String requestedHost) {
        if (!(socket instanceof SSLSocket)) return socket;
        String host = requestedHost == null || requestedHost.trim().isEmpty() ? expectedHost : requestedHost.trim();
        if (!expectedHost.equalsIgnoreCase(host)) host = expectedHost;

        // Android 5-7 Conscrypt exposes setHostname(String). Calling it before
        // the handshake is the most reliable way to force SNI on Evotor-era OS.
        invokeSocketMethod(socket, "setHostname", new Class<?>[]{String.class}, new Object[]{host});
        invokeSocketMethod(socket, "setUseSessionTickets", new Class<?>[]{boolean.class}, new Object[]{Boolean.TRUE});

        // Standard JSSE SNI is available on newer runtimes. Reflection keeps the
        // APK compatible with API 21, where SNIHostName/setServerNames may not exist.
        applyStandardSni((SSLSocket) socket, host);
        return socket;
    }

    private static boolean invokeSocketMethod(Socket socket, String name, Class<?>[] parameterTypes, Object[] args) {
        Class<?> type = socket.getClass();
        while (type != null) {
            try {
                Method method = type.getDeclaredMethod(name, parameterTypes);
                method.setAccessible(true);
                method.invoke(socket, args);
                return true;
            } catch (Exception ignored) {
                type = type.getSuperclass();
            }
        }
        return false;
    }

    private static void applyStandardSni(SSLSocket socket, String host) {
        try {
            Class<?> sniHostNameClass = Class.forName("javax.net.ssl.SNIHostName");
            Constructor<?> constructor = sniHostNameClass.getConstructor(String.class);
            Object serverName = constructor.newInstance(host);
            SSLParameters parameters = socket.getSSLParameters();
            Method setServerNames = SSLParameters.class.getMethod("setServerNames", List.class);
            setServerNames.invoke(parameters, Collections.singletonList(serverName));
            socket.setSSLParameters(parameters);
        } catch (Exception ignored) {
            // Legacy Conscrypt path above is expected to handle Evotor-era Android.
        }
    }
}
