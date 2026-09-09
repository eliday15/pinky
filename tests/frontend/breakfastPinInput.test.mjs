import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const kiosk = await readFile(
    new URL('../../resources/js/Pages/Breakfasts/Kiosk.vue', import.meta.url),
    'utf8',
);

test('breakfast kiosk uses a masked physical-keyboard field without a numeric pad', () => {
    assert.doesNotMatch(kiosk, /PinPad/);
    assert.match(kiosk, /<form autocomplete="off" @submit\.prevent="submitPin">/);
    assert.match(kiosk, /id="breakfast_pin"[\s\S]*?type="text"/);
    assert.match(kiosk, /id="breakfast_pin"[\s\S]*?autofocus/);
    assert.match(kiosk, /data-lpignore="true"/);
    assert.match(kiosk, /data-1p-ignore="true"/);
    assert.match(kiosk, /data-bwignore="true"/);
    assert.match(kiosk, /class="absolute inset-0 z-10 h-full w-full cursor-text opacity-0"/);
    assert.match(kiosk, /aria-hidden="true"[\s\S]*?v-for="index in pin\.length"/);
    assert.match(kiosk, /focus-within:border-pink-500/);
    assert.doesNotMatch(kiosk, /color:\s*transparent/);
    assert.doesNotMatch(kiosk, /caret-color:\s*transparent/);
    assert.doesNotMatch(kiosk, /-webkit-text-security\s*:/);
    assert.doesNotMatch(kiosk, /inputmode="numeric"/);
});
