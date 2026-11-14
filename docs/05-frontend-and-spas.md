# 05. Frontend SDK & Single Page Applications (SPAs)

The client-side experience is powered by a dependency-free JavaScript SDK (`assets/js/aegis.js`) and an optional static Web Worker (`assets/js/aegis-worker.js`).

---

## 1. Vanilla JavaScript SDK

### Basic Initialization:
```javascript
document.addEventListener('DOMContentLoaded', () => {
    const aegis = new Aegis({
        container: '#aegis-container',           // CSS selector for widget badge
        endpoint: '/aegis/challenge',            // Server challenge endpoint
        workerUrl: '/vendor/aegis/js/aegis-worker.js', // Static worker script
        payloadField: 'aegis_payload',           // Hidden form input name
        timeoutMs: 10000,                        // Maximum solver timeout
        onSuccess: (payload) => {
            console.log('Challenge solved successfully');
        },
        onError: (err) => {
            console.warn('Challenge encountered an error:', err);
        }
    });
});
```

### Form Submission Gating (`requestSubmit`):
If a user hits "Submit" while the background Proof of Work is still computing, the SDK:
1. Intercepts and holds the form submit event.
2. Updates the accessible widget state to an active solving spinner.
3. Once the worker finishes and populates the hidden `aegis_payload` input, it re-submits the form natively using `form.requestSubmit()`, ensuring full browser validation rules still fire.

---

## 2. React Integration

```jsx
import React, { useEffect, useRef, useState } from 'react';

export function ProtectedForm() {
    const [payload, setPayload] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const containerRef = useRef(null);

    useEffect(() => {
        const aegis = new window.Aegis({
            container: containerRef.current,
            endpoint: '/api/aegis/challenge',
            onSuccess: (token) => {
                setPayload(token);
            }
        });

        return () => {
            aegis.destroy?.();
        };
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setIsSubmitting(true);

        const response = await fetch('/api/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                data: 'example',
                aegis_payload: payload
            })
        });

        const res = await response.json();
        setIsSubmitting(false);
    };

    return (
        <form onSubmit={handleSubmit}>
            <input type="text" name="message" required />
            <div ref={containerRef} />
            <button type="submit" disabled={isSubmitting}>Submit</button>
        </form>
    );
}
```

---

## 3. Vue 3 Integration

```vue
<template>
    <form @submit.prevent="handleSubmit">
        <input v-model="email" type="email" placeholder="Email" required />
        <div ref="captchaContainer"></div>
        <button type="submit" :disabled="!aegisPayload">Sign Up</button>
    </form>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const email = ref('');
const aegisPayload = ref('');
const captchaContainer = ref(null);

onMounted(() => {
    new window.Aegis({
        container: captchaContainer.value,
        endpoint: '/aegis/challenge',
        onSuccess: (token) => {
            aegisPayload.value = token;
        }
    });
});

const handleSubmit = async () => {
    await fetch('/api/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: email.value,
            aegis_payload: aegisPayload.value
        })
    });
};
</script>
```

---

## 4. Svelte Integration

```svelte
<script>
    import { onMount } from 'svelte';

    let container;
    let payload = '';

    onMount(() => {
        const aegis = new window.Aegis({
            container: container,
            endpoint: '/aegis/challenge',
            onSuccess: (token) => {
                payload = token;
            }
        });
    });

    function onSubmit(e) {
        // payload is automatically included in form data if input exists
    }
</script>

<form on:submit={onSubmit}>
    <input type="text" name="query" required />
    <input type="hidden" name="aegis_payload" value={payload} />
    <div bind:this={container}></div>
    <button type="submit">Search</button>
</form>
```

---

## 5. Accessibility (WCAG 2.1 AA) & Motion Preferences

The SDK and CSS styles (`assets/css/aegis.css`) follow modern accessibility standards:
- **ARIA Live Regions**: The widget contains an `aria-live="polite"` status element announcing progress (`"Verifying your browser..."`, `"Verification successful"`, `"Verification failed, click to retry"`).
- **Visible Keyboard Focus**: Clear high-contrast focus rings (`outline: 2px solid #2563eb`) are applied to retry buttons and slider handles for keyboard-only navigation.
- **Reduced Motion Support**:
  ```css
  @media (prefers-reduced-motion: reduce) {
      .aegis-spinner, .aegis-slider-track {
          animation: none !important;
          transition: none !important;
      }
  }
  ```
- **Zero Fingerprinting**: Does not collect battery status, canvas data, WebGL hashes, audio context dumps, or private device sensors.
