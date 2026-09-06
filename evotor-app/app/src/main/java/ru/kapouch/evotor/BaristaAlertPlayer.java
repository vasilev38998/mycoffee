package ru.kapouch.evotor;

import android.content.Context;
import android.media.AudioManager;
import android.media.ToneGenerator;

final class BaristaAlertPlayer {
    private BaristaAlertPlayer() {}

    static void playNewOrder(Context context) {
        play(context, 3);
    }

    static void playTest(Context context) {
        play(context, 1);
    }

    private static void play(Context context, int repeats) {
        final int stream = chooseStream(context);
        new Thread(() -> {
            ToneGenerator generator = null;
            try {
                generator = new ToneGenerator(stream, 100);
                for (int i = 0; i < repeats; i++) {
                    generator.startTone(ToneGenerator.TONE_CDMA_ALERT_CALL_GUARD, 650);
                    Thread.sleep(850L);
                }
            } catch (Throwable ignored) {
            } finally {
                if (generator != null) {
                    try { generator.release(); } catch (Throwable ignored) {}
                }
            }
        }, "kapouch-order-sound").start();
    }

    private static int chooseStream(Context context) {
        AudioManager audio = (AudioManager) context.getSystemService(Context.AUDIO_SERVICE);
        if (audio == null) return AudioManager.STREAM_ALARM;
        try {
            if (audio.getStreamVolume(AudioManager.STREAM_ALARM) > 0) return AudioManager.STREAM_ALARM;
            if (audio.getStreamVolume(AudioManager.STREAM_MUSIC) > 0) return AudioManager.STREAM_MUSIC;
            if (audio.getStreamVolume(AudioManager.STREAM_NOTIFICATION) > 0) return AudioManager.STREAM_NOTIFICATION;
        } catch (Throwable ignored) {
        }
        return AudioManager.STREAM_ALARM;
    }
}
