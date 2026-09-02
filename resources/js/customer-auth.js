/*
 | Firebase client glue for the customer auth screens (auth rebuild).
 |
 | The Livewire components never trust anything from here directly — every
 | token handed back is re-verified server-side by
 | App\Services\Auth\GoogleFirebaseTokenVerifier. This module's only job is
 | to run the Firebase JS SDK flows the browser must do (phone OTP with an
 | invisible reCAPTCHA, Google popup) and post the resulting ID token to
 | the active Livewire component as a Livewire event.
 |
 | Loaded only on auth pages, via @push('scripts') → @stack('scripts') in
 | the customer layout.
 |
 | Public config is read from Vite env (VITE_FIREBASE_*). When it is absent
 | (e.g. local dev without a Firebase project) every entry point degrades
 | to a visible "sign-in is not configured" error rather than throwing.
 */
import { initializeApp, getApps } from 'firebase/app';
import {
    getAuth,
    RecaptchaVerifier,
    signInWithPhoneNumber,
    GoogleAuthProvider,
    signInWithPopup,
} from 'firebase/auth';

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
};

let auth = null;
let recaptcha = null;
let confirmationResult = null;

function fail(message) {
    if (window.Livewire) {
        window.Livewire.dispatch('firebase-error', { message });
    } else {
        console.error('[customer-auth]', message);
    }
}

function ensureAuth() {
    if (!config.apiKey || !config.projectId) {
        fail('Mobile / Google sign-in is not configured for this site yet.');
        return null;
    }
    if (!auth) {
        const app = getApps().length ? getApps()[0] : initializeApp(config);
        auth = getAuth(app);
    }
    return auth;
}

function resetRecaptcha() {
    try {
        recaptcha?.clear();
    } catch (_) {
        /* noop */
    }
    recaptcha = null;
}

document.addEventListener('livewire:init', () => {
    // Component → here: "please send a phone OTP to this E.164 number".
    window.Livewire.on('firebase-send-phone-otp', async (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        const phone = data?.phone;
        const a = ensureAuth();
        if (!a || !phone) return;

        try {
            if (!document.getElementById('firebase-recaptcha')) {
                fail('Verification widget missing. Reload and try again.');
                return;
            }
            if (!recaptcha) {
                recaptcha = new RecaptchaVerifier(a, 'firebase-recaptcha', { size: 'invisible' });
            }
            confirmationResult = await signInWithPhoneNumber(a, phone, recaptcha);
            window.dispatchEvent(new CustomEvent('firebase-phone-otp-sent'));
            // Livewire-side signal: the SMS has really gone out. Auth
            // components that opt in (Signup, provider Register) advance to
            // the code-entry step only on this event, not optimistically;
            // components that don't listen (Login, ForgotPassword,
            // PasswordMigration, GoogleAuth) simply ignore it.
            window.Livewire.dispatch('firebase-phone-otp-sent');
        } catch (e) {
            console.error(e);
            resetRecaptcha();
            fail(e?.code === 'auth/too-many-requests'
                ? 'Too many attempts from this device. Try again later.'
                : 'Could not send the verification code. Check the number and try again.');
        }
    });
});

// Blade → here: the customer typed the SMS code.
window.customerAuth = {
    async confirmPhone(code) {
        if (!confirmationResult) {
            fail('Request a code first.');
            return;
        }
        try {
            const cred = await confirmationResult.confirm(String(code || '').trim());
            const idToken = await cred.user.getIdToken();
            window.Livewire.dispatch('firebase-phone-token', { idToken });
        } catch (e) {
            fail('That code is incorrect or has expired. Request a new one.');
        }
    },

    async google() {
        const a = ensureAuth();
        if (!a) return;
        try {
            const res = await signInWithPopup(a, new GoogleAuthProvider());
            const idToken = await res.user.getIdToken();
            window.Livewire.dispatch('firebase-google-token', { idToken });
        } catch (e) {
            if (e?.code === 'auth/popup-closed-by-user' || e?.code === 'auth/cancelled-popup-request') {
                return; // user backed out — no error banner
            }
            console.error(e);
            fail('Google sign-in could not be completed.');
        }
    },
};
