import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
    new URL('../../resources/js/Pages/Breakfasts/components/FaceScan.vue', import.meta.url),
    'utf8',
);

test('checks for unmount after video.play before starting the scan interval', () => {
    const afterPlay = source.slice(source.indexOf('await video.value.play();'));
    const destroyedGuard = afterPlay.indexOf('if (destroyed)');
    const intervalStart = afterPlay.indexOf('intervalId = setInterval');

    assert.ok(destroyedGuard > 0, 'missing destroyed guard after video.play');
    assert.ok(intervalStart > destroyedGuard, 'scan interval starts before the destroyed guard');
    assert.match(afterPlay.slice(destroyedGuard, intervalStart), /stopAll\(\);/);
    assert.match(afterPlay.slice(destroyedGuard, intervalStart), /return;/);
});
