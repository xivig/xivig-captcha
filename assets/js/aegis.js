/**
 * Xivig Aegis Browser Client SDK v2.0.0
 * Privacy-first, WebWorker-accelerated, CSP-compliant anti-abuse engine.
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) define([], factory);
    else if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.Aegis = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    class AegisClient {
        constructor(options = {}) {
            this.options = Object.assign({
                endpoint: '/aegis/challenge',
                workerUrl: '/vendor/aegis/js/aegis-worker.js',
                containerId: 'aegis-container',
                inputName: 'aegis_payload',
                timeoutMs: 30000,
                maxIterations: 5000000,
                onSuccess: null,
                onError: null
            }, options);

            this.options.maxIterations = Math.max(1000, Math.min(5000000, Number(this.options.maxIterations) || 5000000));
            this.options.timeoutMs = Math.max(3000, Math.min(60000, Number(this.options.timeoutMs) || 30000));
            this.startedAt = Date.now();
            this.resolvedPayload = null;
            this.isSolving = false;
            this.pendingSubmit = false;
            this.form = null;
            this.submitHandler = null;

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.init(), { once: true });
            } else {
                this.init();
            }
        }

        init() {
            const container = document.getElementById(this.options.containerId);
            if (!container) return;

            this.options.endpoint = container.dataset.endpoint || this.options.endpoint;
            this.options.inputName = container.dataset.input || this.options.inputName;
            if (container.dataset.worker) this.options.workerUrl = container.dataset.worker;

            this.form = container.closest('form');
            if (this.form) {
                this.submitHandler = (event) => {
                    if (!this.resolvedPayload) {
                        event.preventDefault();
                        this.pendingSubmit = true;
                        if (!this.isSolving) this.startChallengeFlow();
                    }
                };
                this.form.addEventListener('submit', this.submitHandler);
            }

            this.renderStatus('loading', 'Checking security…');
            this.startChallengeFlow();
        }

        async startChallengeFlow() {
            if (this.isSolving || this.resolvedPayload) return;
            this.isSolving = true;

            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), this.options.timeoutMs);

            try {
                const response = await fetch(this.options.endpoint, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal
                });

                if (!response.ok) {
                    throw new Error(`Challenge request failed (${response.status}).`);
                }

                const challenge = await response.json();
                if (!challenge || typeof challenge.token !== 'string' ||
                    typeof challenge.seed !== 'string' || !/^[a-f0-9]{64}$/.test(challenge.seed) ||
                    !Number.isInteger(challenge.difficulty) || challenge.difficulty < 1 || challenge.difficulty > 5) {
                    throw new Error('Server returned an invalid challenge.');
                }

                // Check if server mandates an interactive step-up challenge
                if (challenge.step_up || challenge.mode === 'interactive') {
                    this.renderInteractiveStepUp(challenge);
                    return;
                }

                const powNonce = await this.solveProofOfWork(challenge.seed, challenge.difficulty, controller.signal);

                const payload = {
                    token: challenge.token,
                    powNonce: powNonce,
                    telemetry: {
                        webdriver: navigator.webdriver === true,
                        durationMs: Math.max(0, Date.now() - this.startedAt)
                    }
                };

                this.resolvedPayload = btoa(JSON.stringify(payload));
                this.injectHiddenField(this.resolvedPayload);
                this.renderStatus('success', 'Security check complete');

                if (typeof this.options.onSuccess === 'function') {
                    this.options.onSuccess(this.resolvedPayload);
                }

                if (this.pendingSubmit && this.form) {
                    this.pendingSubmit = false;
                    this.form.requestSubmit();
                }

            } catch (error) {
                const message = error && error.name === 'AbortError'
                    ? 'Security check timed out. Please try again.'
                    : 'Security check could not be completed. Please retry.';
                this.renderStatus('error', message);
                if (typeof this.options.onError === 'function') {
                    this.options.onError(error);
                }
            } finally {
                clearTimeout(timer);
                this.isSolving = false;
            }
        }

        async solveProofOfWork(seed, difficulty, signal) {
            const targetPrefix = '0'.repeat(difficulty);

            // 1. Try CSP-Compliant External Static Worker First
            if (typeof Worker !== 'undefined' && this.options.workerUrl) {
                try {
                    const worker = new Worker(this.options.workerUrl);
                    return await this.runWorkerPromise(worker, seed, targetPrefix, signal);
                } catch (e) {
                    // Fall back to Blob worker or main thread
                }
            }

            // 2. Try Blob Worker (If CSP allows blob:)
            if (typeof Worker !== 'undefined' && typeof Blob !== 'undefined' && URL.createObjectURL) {
                let worker, objectUrl;
                try {
                    const workerSource = `self.onmessage=async(e)=>{const{seed,targetPrefix,maxIterations}=e.data;const enc=new TextEncoder();const half=Math.ceil(targetPrefix.length/2);for(let n=0;n<maxIterations;n++){if(n%2048===0&&self.cancelled)return;const b=await crypto.subtle.digest('SHA-256',enc.encode(seed+':'+n));const a=new Uint8Array(b);let h='';for(let i=0;i<half;i++)h+=a[i].toString(16).padStart(2,'0');if(h.startsWith(targetPrefix)){postMessage({success:true,nonce:String(n)});return;}}postMessage({success:false,error:'exhausted'});};`;
                    objectUrl = URL.createObjectURL(new Blob([workerSource], { type: 'text/javascript' }));
                    worker = new Worker(objectUrl);
                    const result = await this.runWorkerPromise(worker, seed, targetPrefix, signal);
                    URL.revokeObjectURL(objectUrl);
                    return result;
                } catch (error) {
                    if (worker) worker.terminate();
                    if (objectUrl) URL.revokeObjectURL(objectUrl);
                    if (signal.aborted) throw error;
                }
            }

            // 3. Main-Thread Yielding Fallback
            const enc = new TextEncoder();
            const halfLen = Math.ceil(targetPrefix.length / 2);
            for (let nonce = 0; nonce < this.options.maxIterations; nonce++) {
                if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
                const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(`${seed}:${nonce}`)));
                let hex = '';
                for (let i = 0; i < halfLen; i++) hex += digest[i].toString(16).padStart(2, '0');
                if (hex.startsWith(targetPrefix)) return String(nonce);
                if (nonce % 512 === 0) await new Promise(resolve => setTimeout(resolve, 0));
            }

            throw new Error('Proof-of-work iteration limit reached.');
        }

        runWorkerPromise(worker, seed, targetPrefix, signal) {
            return new Promise((resolve, reject) => {
                const cleanup = () => {
                    worker.terminate();
                    signal.removeEventListener('abort', abortHandler);
                };
                const abortHandler = () => {
                    cleanup();
                    reject(new DOMException('Aborted', 'AbortError'));
                };

                signal.addEventListener('abort', abortHandler, { once: true });

                worker.onmessage = (event) => {
                    cleanup();
                    if (event.data && (event.data.nonce !== undefined || event.data.success)) {
                        resolve(event.data.nonce);
                    } else {
                        reject(new Error(event.data?.error || 'Worker iteration limit reached.'));
                    }
                };

                worker.onerror = () => {
                    cleanup();
                    reject(new Error('Worker execution error.'));
                };

                worker.postMessage({ seed, targetPrefix, maxIterations: this.options.maxIterations });
            });
        }

        renderInteractiveStepUp(challenge) {
            const container = document.getElementById(this.options.containerId);
            if (!container) return;

            container.innerHTML = `
                <div class="aegis-interactive-box" role="region" aria-label="Security puzzle">
                    <p class="aegis-interactive-title">Slide to complete security verification</p>
                    <div class="aegis-slider-track">
                        <div class="aegis-slider-target" style="left: ${challenge.target_hint || 140}px;"></div>
                        <div class="aegis-slider-handle" tabindex="0" role="slider" aria-valuemin="0" aria-valuemax="260" aria-valuenow="0" aria-label="Drag slider to align with target">
                            <span>↔</span>
                        </div>
                    </div>
                </div>
            `;

            const handle = container.querySelector('.aegis-slider-handle');
            const track = container.querySelector('.aegis-slider-track');
            let isDragging = false;
            let startX = 0;
            let currentX = 0;

            const onMove = (clientX) => {
                if (!isDragging) return;
                const rect = track.getBoundingClientRect();
                currentX = Math.max(0, Math.min(260, clientX - rect.left - 20));
                handle.style.left = currentX + 'px';
                handle.setAttribute('aria-valuenow', Math.round(currentX));
            };

            const onEnd = () => {
                if (!isDragging) return;
                isDragging = false;
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onEnd);
                window.removeEventListener('touchmove', onTouchMove);
                window.removeEventListener('touchend', onEnd);

                // Package step-up slider response
                const payload = {
                    token: challenge.token,
                    powNonce: '0',
                    sliderX: Math.round(currentX),
                    telemetry: { durationMs: Date.now() - this.startedAt }
                };

                this.resolvedPayload = btoa(JSON.stringify(payload));
                this.injectHiddenField(this.resolvedPayload);
                this.renderStatus('success', 'Security check complete');

                if (this.pendingSubmit && this.form) {
                    this.pendingSubmit = false;
                    this.form.requestSubmit();
                }
            };

            const onMouseMove = (e) => onMove(e.clientX);
            const onTouchMove = (e) => e.touches[0] && onMove(e.touches[0].clientX);

            handle.addEventListener('mousedown', (e) => {
                isDragging = true;
                startX = e.clientX;
                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onEnd);
            });

            handle.addEventListener('touchstart', (e) => {
                isDragging = true;
                window.addEventListener('touchmove', onTouchMove, { passive: true });
                window.addEventListener('touchend', onEnd);
            }, { passive: true });

            // Keyboard accessibility for slider
            handle.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                    currentX = Math.min(260, currentX + 10);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                    currentX = Math.max(0, currentX - 10);
                } else if (e.key === 'Enter') {
                    onEnd();
                    return;
                }
                handle.style.left = currentX + 'px';
                handle.setAttribute('aria-valuenow', Math.round(currentX));
            });
        }

        injectHiddenField(payload) {
            const container = document.getElementById(this.options.containerId);
            const target = this.form || container;
            let input = Array.from(target.querySelectorAll('input[type="hidden"]')).find(el => el.name === this.options.inputName);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = this.options.inputName;
                target.appendChild(input);
            }
            input.value = payload;
        }

        renderStatus(status, message) {
            const container = document.getElementById(this.options.containerId);
            if (!container) return;
            container.replaceChildren();

            const badge = document.createElement('div');
            badge.className = `aegis-badge aegis-${status}`;
            badge.setAttribute('role', 'status');
            badge.setAttribute('aria-live', 'polite');

            const text = document.createElement('span');
            text.className = 'aegis-text';
            text.textContent = message;
            badge.appendChild(text);

            if (status === 'error') {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'aegis-retry';
                retry.textContent = 'Retry';
                retry.addEventListener('click', () => {
                    this.startedAt = Date.now();
                    this.renderStatus('loading', 'Retrying security check…');
                    this.startChallengeFlow();
                });
                badge.appendChild(retry);
            }

            container.appendChild(badge);
        }

        getPayload() {
            return this.resolvedPayload;
        }

        destroy() {
            if (this.form && this.submitHandler) {
                this.form.removeEventListener('submit', this.submitHandler);
            }
        }
    }

    return AegisClient;
}));
