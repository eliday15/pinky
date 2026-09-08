import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import test from 'node:test';

const packageJson = JSON.parse(await readFile(new URL('../../package.json', import.meta.url), 'utf8'));
const faceScanSource = await readFile(
    new URL('../../resources/js/Pages/Breakfasts/components/FaceScan.vue', import.meta.url),
    'utf8',
);

const WASM_FILES = [
    'tfjs-backend-wasm.wasm',
    'tfjs-backend-wasm-simd.wasm',
    'tfjs-backend-wasm-threaded-simd.wasm',
];

test('pins the TensorFlow WASM package to the face-api compatible release', () => {
    assert.equal(packageJson.dependencies['@tensorflow/tfjs-backend-wasm'], '4.22.0');
});

for (const filename of WASM_FILES) {
    test(`${filename} exists and is imported as a Vite URL asset`, async () => {
        const asset = new URL(
            `../../node_modules/@tensorflow/tfjs-backend-wasm/dist/${filename}`,
            import.meta.url,
        );

        assert.ok((await stat(asset)).size > 100_000, `${filename} is unexpectedly empty`);
        assert.match(
            faceScanSource,
            new RegExp(`@tensorflow/tfjs-backend-wasm/dist/${filename.replaceAll('.', '\\.')}` + String.raw`\?url`),
        );
    });
}
