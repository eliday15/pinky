<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

// Escáner facial del kiosco: carga face-api.js dinámicamente (solo esta
// página paga el peso), calcula el descriptor de referencia desde la foto del
// empleado y compara frames en vivo de la webcam. Con 2 frames consecutivos
// bajo el umbral emite 'verified' con la distancia y el snapshot de evidencia.
const props = defineProps({
    photoUrl: { type: String, required: true },
    maxDistance: { type: Number, default: 0.5 },
});

const emit = defineEmits(['verified', 'error']);

const video = ref(null);
const statusText = ref('Cargando reconocimiento facial...');
const scanning = ref(false);

let faceapi = null;
let stream = null;
let intervalId = null;
let referenceDescriptor = null;
let consecutiveMatches = 0;
let destroyed = false;
let detectionInFlight = false;

const MODELS_URI = '/models-face';
const REQUIRED_CONSECUTIVE = 2;
const REFERENCE_DETECTION_OPTIONS = [
    { inputSize: 320, scoreThreshold: 0.15 },
    { inputSize: 416, scoreThreshold: 0.1 },
];

const stopAll = () => {
    if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
    }
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
};

const fail = (message) => {
    stopAll();
    emit('error', message);
};

const captureSnapshot = () => {
    const canvas = document.createElement('canvas');
    canvas.width = video.value.videoWidth;
    canvas.height = video.value.videoHeight;
    canvas.getContext('2d').drawImage(video.value, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.8);
};

const detectFrame = async () => {
    if (destroyed || detectionInFlight || !video.value || video.value.readyState < 2) return;
    detectionInFlight = true;

    try {
        const detection = await faceapi
            .detectSingleFace(video.value, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (destroyed) return;

        if (!detection) {
            consecutiveMatches = 0;
            statusText.value = 'Acércate y mira a la cámara...';
            return;
        }

        const distance = faceapi.euclideanDistance(referenceDescriptor, detection.descriptor);

        if (distance <= props.maxDistance) {
            consecutiveMatches += 1;
            statusText.value = 'Verificando...';
            if (consecutiveMatches >= REQUIRED_CONSECUTIVE) {
                const snapshot = captureSnapshot();
                stopAll();
                emit('verified', { distance, snapshot });
            }
        } else {
            consecutiveMatches = 0;
            statusText.value = 'Rostro no reconocido, intenta de frente y con buena luz.';
        }
    } finally {
        detectionInFlight = false;
    }
};

// WebGL precision varies between browsers/GPUs. A face close to TinyFace's
// default confidence threshold can therefore be detected on one computer but
// missed on another. Retry only the reference-photo detection progressively;
// identity matching still uses the configured maxDistance unchanged.
const detectReferenceFace = async (image) => {
    for (const options of REFERENCE_DETECTION_OPTIONS) {
        const detection = await faceapi
            .detectSingleFace(image, new faceapi.TinyFaceDetectorOptions(options))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (detection) return detection;
    }

    return null;
};

onMounted(async () => {
    try {
        faceapi = await import('@vladmandic/face-api');

        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
        ]);
        if (destroyed) return;

        statusText.value = 'Analizando foto de referencia...';
        const referenceImage = await faceapi.fetchImage(props.photoUrl);
        const reference = await detectReferenceFace(referenceImage);

        if (!reference) {
            fail('No se detectó un rostro en la foto registrada del empleado. Acude a RRHH para tomar una nueva foto.');
            return;
        }
        referenceDescriptor = reference.descriptor;
        if (destroyed) return;

        statusText.value = 'Encendiendo cámara...';
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        });
        if (destroyed) {
            stopAll();
            return;
        }
        video.value.srcObject = stream;
        await video.value.play();

        scanning.value = true;
        statusText.value = 'Mira a la cámara...';
        intervalId = setInterval(detectFrame, 700);
    } catch (error) {
        if (error?.name === 'NotAllowedError') {
            fail('La cámara está bloqueada. Permite el acceso a la cámara en este dispositivo.');
        } else {
            fail('No se pudo iniciar el reconocimiento facial. Verifica la cámara e intenta de nuevo.');
        }
    }
});

onBeforeUnmount(() => {
    destroyed = true;
    stopAll();
});
</script>

<template>
    <div class="flex flex-col items-center">
        <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-gray-900 aspect-[4/3]">
            <video ref="video" autoplay playsinline muted class="w-full h-full object-cover -scale-x-100" />
            <div v-if="!scanning" class="absolute inset-0 flex items-center justify-center bg-gray-900/70">
                <svg class="w-10 h-10 text-pink-400 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
            </div>
            <div class="absolute inset-x-0 bottom-0 bg-black/60 px-4 py-3 text-center">
                <p class="text-white text-lg">{{ statusText }}</p>
            </div>
        </div>
    </div>
</template>
