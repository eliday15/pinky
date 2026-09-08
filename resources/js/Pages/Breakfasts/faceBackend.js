export const FACE_BACKEND_ORDER = ['webgl', 'wasm', 'cpu'];

export class FaceBackendInitializationError extends Error {
    constructor(attempts) {
        const detail = attempts
            .map(({ backend, error }) => `${backend}: ${error?.message || String(error)}`)
            .join('; ');

        super(`No se pudo iniciar ningún motor de reconocimiento facial. ${detail}`);
        this.name = 'FaceBackendInitializationError';
        this.attempts = attempts;
    }
}

/**
 * Select a TensorFlow backend deterministically.
 *
 * face-api registers WebGL and WASM with the same priority, so relying on its
 * implicit choice makes the result depend on module registration order. WASM
 * paths are configured before any backend is initialized, then each available
 * backend is tried in the order that best fits the kiosk hardware.
 */
export const initializeFaceBackend = async (tf, wasmPaths, onAttempt = () => {}) => {
    tf.setWasmPaths(wasmPaths);

    const attempts = [];

    for (const backend of FACE_BACKEND_ORDER) {
        onAttempt(backend);
        try {
            const selected = await tf.setBackend(backend);
            if (!selected) throw new Error('el navegador rechazó este motor');

            await tf.ready();
            return backend;
        } catch (error) {
            attempts.push({ backend, error });
        }
    }

    throw new FaceBackendInitializationError(attempts);
};
