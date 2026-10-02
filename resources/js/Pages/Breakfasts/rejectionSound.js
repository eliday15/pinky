// Prepare from a click/submit before awaiting the server or camera so browsers
// permit the later alert. Audio failures must never interrupt kiosk validation.
export function createRejectionSound() {
    let context = null;
    let lastPlayedAt = -Infinity;

    const prepare = () => {
        try {
            const AudioContext = globalThis.AudioContext || globalThis.webkitAudioContext;
            if (!AudioContext) return;
            context ??= new AudioContext();
            if (context.state === 'suspended') context.resume().catch(() => {});
        } catch {
            // Visual rejection remains available on devices without audio.
        }
    };

    const play = () => {
        if (!context || context.state !== 'running') return;
        const now = context.currentTime;
        if (now - lastPlayedAt < 2.5) return;
        try {
            const tone = context.createOscillator();
            const volume = context.createGain();
            tone.type = 'sine';
            tone.frequency.setValueAtTime(440, now);
            tone.frequency.setValueAtTime(220, now + 0.18);
            volume.gain.setValueAtTime(0, now);
            volume.gain.linearRampToValueAtTime(0.3, now + 0.015);
            volume.gain.setValueAtTime(0.3, now + 0.32);
            volume.gain.linearRampToValueAtTime(0, now + 0.4);
            tone.connect(volume);
            volume.connect(context.destination);
            tone.onended = () => {
                tone.disconnect();
                volume.disconnect();
            };
            tone.start(now);
            tone.stop(now + 0.4);
            lastPlayedAt = now;
        } catch {
            // Keep the visual error even if an audio device is unavailable.
        }
    };

    const dispose = () => {
        if (context && context.state !== 'closed') context.close().catch(() => {});
        context = null;
    };

    return { prepare, play, dispose };
}
