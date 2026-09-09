const loadingByRuntime = new WeakMap();

/**
 * Load model tensors once per face-api runtime.
 *
 * FaceScan is mounted once per employee. Calling loadFromUri on every mount
 * replaces the net parameters without disposing the previous tensors, which
 * steadily grows GPU/WASM memory in a kiosk that processes many people.
 */
export const ensureFaceModels = (faceapi, modelsUri) => {
    const existing = loadingByRuntime.get(faceapi);
    if (existing) return existing;

    const nets = [
        faceapi.nets.tinyFaceDetector,
        faceapi.nets.faceLandmark68Net,
        faceapi.nets.faceRecognitionNet,
    ];
    const unloadedNets = nets.filter((net) => !net.isLoaded);

    const loading = Promise.allSettled(
        unloadedNets.map((net) => net.loadFromUri(modelsUri)),
    ).then((results) => {
        const failed = results.find(({ status }) => status === 'rejected');
        if (failed) throw failed.reason;
    }).catch((error) => {
        // A transient network failure must be retryable on the next scan.
        // allSettled guarantees no loader from this attempt remains in flight
        // when the cache is cleared, so retries cannot overwrite model params
        // concurrently with the previous attempt.
        loadingByRuntime.delete(faceapi);
        throw error;
    });

    loadingByRuntime.set(faceapi, loading);
    return loading;
};
