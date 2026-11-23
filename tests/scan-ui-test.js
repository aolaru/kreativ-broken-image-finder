const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const script = fs.readFileSync('js/kbif-scan.js', 'utf8');

function run(finishResponse, transportFailure) {
    const elements = {};
    const requests = [];
    const window = { location: { href: 'https://example.test/admin?page=kbif' } };
    function $(selector) {
        if (typeof selector === 'function') { selector($); return; }
        return elements[selector] ||= {
            on(event, callback) { this.callback = callback; return this; },
            show() { return this; }, css() { return this; },
            text(value) { this.value = value; return this; }
        };
    }
    $.post = (url, data) => {
        requests.push(data.step);
        const response = data.step === 'init' ? { success: true, data: { total: 0 } } : finishResponse;
        const failed = data.step === 'finish' && transportFailure;
        return {
            done(callback) { if (!failed) callback(response); return this; },
            fail(callback) { if (failed) callback(); return this; }
        };
    };
    vm.runInNewContext(script, { jQuery: $, KBIF_Ajax: { ajax_url: '/ajax', nonce: 'test' }, window, URL, alert() {}, setTimeout });
    elements['#kbif-start-scan'].callback({ preventDefault() {} });
    return { elements, requests, window };
}

let result = run({ success: true }, false);
assert.deepStrictEqual(result.requests, ['init', 'finish']);
assert.match(result.window.location.href, /kbif_scanned=1/);
for (const failure of [run({ success: false }, false), run(null, true)]) {
    assert.doesNotMatch(failure.window.location.href, /kbif_scanned=1/);
    assert.match(failure.elements['#kbif-progress-text'].value, /Could not save/);
}
console.log('Scan finalization regression tests passed.');
