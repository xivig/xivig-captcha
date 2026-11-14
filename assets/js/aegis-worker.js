/**
 * Xivig Aegis Standalone CSP-Compliant Web Worker v2.0
 * Complies with strict 'worker-src self' Content Security Policies.
 */
self.onmessage = async function (e) {
    'use strict';
    const { seed, targetPrefix, maxIterations } = e.data;
    const max = maxIterations || 5000000;
    const enc = new TextEncoder();
    const halfLen = Math.ceil(targetPrefix.length / 2);

    for (let nonce = 0; nonce < max; nonce++) {
        // Yield check for cancellation
        if (nonce % 2048 === 0 && self.cancelled) {
            return;
        }

        const msgBuffer = enc.encode(seed + ':' + nonce);
        const hashBuf = await crypto.subtle.digest('SHA-256', msgBuffer);
        const hashArr = new Uint8Array(hashBuf);

        let hex = '';
        for (let i = 0; i < halfLen; i++) {
            hex += hashArr[i].toString(16).padStart(2, '0');
        }

        if (hex.startsWith(targetPrefix)) {
            self.postMessage({ success: true, nonce: String(nonce) });
            return;
        }
    }

    self.postMessage({ success: false, error: 'MAX_ITERATIONS_EXCEEDED' });
};
