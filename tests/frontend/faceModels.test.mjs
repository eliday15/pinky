import assert from 'node:assert/strict';
import test from 'node:test';

import { ensureFaceModels } from '../../resources/js/Pages/Breakfasts/faceModels.js';

const fakeFaceApi = (load) => {
    const makeNet = (name) => {
        const net = {
            isLoaded: false,
            async loadFromUri(uri) {
                await load(uri, name);
                net.isLoaded = true;
            },
        };
        return net;
    };

    return {
        nets: {
            tinyFaceDetector: makeNet('detector'),
            faceLandmark68Net: makeNet('landmarks'),
            faceRecognitionNet: makeNet('recognition'),
        },
    };
};

test('loads model tensors only once across sequential employee scans', async () => {
    const calls = [];
    const faceapi = fakeFaceApi(async (uri) => calls.push(uri));

    await ensureFaceModels(faceapi, '/models-face');
    await ensureFaceModels(faceapi, '/models-face');
    await ensureFaceModels(faceapi, '/models-face');

    assert.deepEqual(calls, ['/models-face', '/models-face', '/models-face']);
});

test('shares one in-flight model load between overlapping mounts', async () => {
    let release;
    let calls = 0;
    const gate = new Promise((resolve) => { release = resolve; });
    const faceapi = fakeFaceApi(async () => {
        calls += 1;
        await gate;
    });

    const first = ensureFaceModels(faceapi, '/models-face');
    const second = ensureFaceModels(faceapi, '/models-face');
    assert.equal(first, second);
    release();
    await Promise.all([first, second]);
    assert.equal(calls, 3);
});

test('does not reload models that were already loaded before the first call', async () => {
    const loaded = [];
    const faceapi = fakeFaceApi(async (_uri, name) => loaded.push(name));
    faceapi.nets.tinyFaceDetector.isLoaded = true;
    faceapi.nets.faceRecognitionNet.isLoaded = true;

    await ensureFaceModels(faceapi, '/models-face');

    assert.deepEqual(loaded, ['landmarks']);
});

test('allows a retry after a model download fails', async () => {
    let shouldFail = true;
    let calls = 0;
    const faceapi = fakeFaceApi(async () => {
        calls += 1;
        if (shouldFail) throw new Error('network');
    });

    await assert.rejects(ensureFaceModels(faceapi, '/models-face'), /network/);
    shouldFail = false;
    await ensureFaceModels(faceapi, '/models-face');
    assert.equal(calls, 6);
});

test('does not clear the cache until every loader in a failed attempt settles', async () => {
    let releasePending;
    const pending = new Promise((resolve) => { releasePending = resolve; });
    let calls = 0;
    const faceapi = fakeFaceApi(async () => {
        calls += 1;
        if (calls === 1) throw new Error('first download failed');
        if (calls <= 3) await pending;
    });

    const failedAttempt = ensureFaceModels(faceapi, '/models-face');
    await new Promise((resolve) => setImmediate(resolve));

    // The first loader already failed, but the other two are still pending;
    // another mount must share the same attempt instead of starting 3 more.
    assert.equal(ensureFaceModels(faceapi, '/models-face'), failedAttempt);
    assert.equal(calls, 3);

    releasePending();
    await assert.rejects(failedAttempt, /first download failed/);

    await ensureFaceModels(faceapi, '/models-face');
    assert.equal(calls, 4);
});
