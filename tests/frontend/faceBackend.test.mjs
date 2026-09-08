import assert from 'node:assert/strict';
import test from 'node:test';

import {
    FACE_BACKEND_ORDER,
    FaceBackendInitializationError,
    initializeFaceBackend,
} from '../../resources/js/Pages/Breakfasts/faceBackend.js';

const fakeTensorFlow = (outcomes) => {
    const calls = [];
    let configuredPaths = null;

    return {
        calls,
        get configuredPaths() { return configuredPaths; },
        setWasmPaths(paths) {
            calls.push('paths');
            configuredPaths = paths;
        },
        async setBackend(backend) {
            calls.push(`backend:${backend}`);
            const outcome = outcomes[backend];
            if (outcome instanceof Error) throw outcome;
            return outcome;
        },
        async ready() {
            calls.push('ready');
        },
    };
};

test('prefers WebGL and configures absolute versioned WASM paths first', async () => {
    const tf = fakeTensorFlow({ webgl: true });
    const paths = {
        'tfjs-backend-wasm.wasm': '/build/assets/tfjs-backend-wasm-abc123.wasm',
        'tfjs-backend-wasm-simd.wasm': '/build/assets/tfjs-backend-wasm-simd-def456.wasm',
        'tfjs-backend-wasm-threaded-simd.wasm': '/build/assets/tfjs-backend-wasm-threaded-simd-ghi789.wasm',
    };

    assert.equal(await initializeFaceBackend(tf, paths), 'webgl');
    assert.deepEqual(tf.calls, ['paths', 'backend:webgl', 'ready']);
    assert.equal(tf.configuredPaths, paths);
});

test('falls back from unavailable WebGL to WASM', async () => {
    const tf = fakeTensorFlow({ webgl: new Error('WebGL disabled'), wasm: true });
    const attempted = [];

    assert.equal(await initializeFaceBackend(tf, {}, (backend) => attempted.push(backend)), 'wasm');
    assert.deepEqual(tf.calls, ['paths', 'backend:webgl', 'backend:wasm', 'ready']);
    assert.deepEqual(attempted, ['webgl', 'wasm']);
});

test('falls back to CPU when WebGL and WASM are unavailable', async () => {
    const tf = fakeTensorFlow({ webgl: false, wasm: new Error('missing binary'), cpu: true });

    assert.equal(await initializeFaceBackend(tf, {}), 'cpu');
    assert.deepEqual(tf.calls, ['paths', 'backend:webgl', 'backend:wasm', 'backend:cpu', 'ready']);
});

test('reports every attempted backend when none can initialize', async () => {
    const tf = fakeTensorFlow({ webgl: false, wasm: false, cpu: false });

    await assert.rejects(
        initializeFaceBackend(tf, {}),
        (error) => {
            assert.ok(error instanceof FaceBackendInitializationError);
            assert.deepEqual(error.attempts.map(({ backend }) => backend), FACE_BACKEND_ORDER);
            assert.match(error.message, /webgl:/);
            assert.match(error.message, /wasm:/);
            assert.match(error.message, /cpu:/);
            return true;
        },
    );
});
