import assert from 'node:assert/strict';
import test from 'node:test';
import { createRejectionSound } from '../../resources/js/Pages/Breakfasts/rejectionSound.js';

test('alerts are armed on a user gesture, throttled, and disposed', async () => {
    const original = globalThis.AudioContext;
    let created = 0;
    let played = 0;
    let resumed = 0;
    let closed = 0;
    let context;
    globalThis.AudioContext = class {
        state = 'suspended';
        currentTime = 1;
        destination = {};
        constructor() { created += 1; context = this; }
        async resume() { resumed += 1; this.state = 'running'; }
        async close() { closed += 1; this.state = 'closed'; }
        createOscillator() {
            return {
                frequency: { setValueAtTime() {} },
                connect() {}, disconnect() {},
                start() { played += 1; }, stop() {},
            };
        }
        createGain() {
            return {
                gain: { setValueAtTime() {}, linearRampToValueAtTime() {} },
                connect() {}, disconnect() {},
            };
        }
    };
    try {
        const sound = createRejectionSound();
        sound.play();
        assert.equal(created, 0, 'camera/server errors alone cannot request autoplay');
        sound.prepare();
        assert.equal(resumed, 1);
        await Promise.resolve();
        sound.play();
        sound.play();
        assert.equal(played, 1, 'repeated rejected frames produce only one alert');
        context.currentTime += 3;
        sound.play();
        assert.equal(played, 2, 'later rejection can alert again');
        sound.prepare();
        assert.equal(created, 1, 'reuse one audio context per kiosk');
        sound.dispose();
        sound.play();
        assert.equal(closed, 1);
        assert.equal(played, 2);
    } finally {
        globalThis.AudioContext = original;
    }
});

test('unsupported audio and blocked autoplay never break rejection handling', () => {
    const original = globalThis.AudioContext;
    try {
        globalThis.AudioContext = undefined;
        const unavailable = createRejectionSound();
        assert.doesNotThrow(() => { unavailable.prepare(); unavailable.play(); unavailable.dispose(); });
        globalThis.AudioContext = class {
            state = 'suspended';
            resume() { return Promise.reject(new Error('autoplay blocked')); }
            close() { return Promise.resolve(); }
        };
        const blocked = createRejectionSound();
        assert.doesNotThrow(() => { blocked.prepare(); blocked.play(); blocked.dispose(); });
    } finally {
        globalThis.AudioContext = original;
    }
});
