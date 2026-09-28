<?php

require __DIR__ . '/../config/config.php';

$method = $_GET['method'] ?? 'face';

$allowed = [
    'face',
    'voice',
    'fingerprint'
];

if (!in_array($method, $allowed, true)) {
    $method = 'face';
}

$csrf = csrf_token();

// Modo registro: solo si ya hay sesión iniciada
$setup = ($_GET['setup'] ?? '') === '1' && current_user() !== null;

?>

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, maximum-scale=1.0"
>

<title>Autenticación biométrica</title>

<link
    rel="stylesheet"
    href="assets/style.css"
>

<script
    defer
    src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"
></script>

</head>

<body>

<main class="container">

<section class="card biometric-card">

<a href="index.php">
    ← Volver
</a>

<h1 id="title">
    Autenticación
</h1>

<p id="status" class="note">
    Preparando dispositivo...
</p>

<div
    id="secureWarning"
    class="alert danger hidden"
></div>

<!-- EMAIL -->

<div class="field">

<label for="email">
    Nombre del usuario
</label>

<input
    id="email"
    type="text"
    placeholder="Escribe tu nombre"
    autocomplete="name"
    required
>

</div>

<!-- CÁMARA -->

<div
    id="cameraBox"
    class="media-box"
>

<video
    id="video"
    autoplay
    muted
    playsinline
></video>

</div>

<!-- VOZ -->

<div
    id="voiceBox"
    class="media-box hidden"
>

<div class="mic-icon">
    🎤
</div>

<p>
    Habla normalmente
</p>

<div class="level">

<span id="levelBar"></span>

</div>

</div>

<!-- HUELLA -->

<div
    id="fingerprintBox"
    class="media-box hidden"
>

<div class="fingerprint-icon">
    👆
</div>

<p>
    Usa la huella, Face ID o PIN de tu dispositivo.
</p>

</div>

<button
    id="start"
    type="button"
>
    Iniciar autenticación
</button>

<button
    id="stop"
    type="button"
    class="secondary"
>
    Detener
</button>

<form
    id="authForm"
    method="POST"
    action="biometric_very.php"
>

<input
    type="hidden"
    name="csrf"
    value="<?= e($csrf) ?>"
>

<input
    type="hidden"
    name="method"
    value="<?= e($method) ?>"
>

<input
    type="hidden"
    name="setup"
    value="<?= $setup ? '1' : '0' ?>"
>

<input
    type="hidden"
    name="email"
    id="emailValue"
>

<input
    type="hidden"
    name="template"
    id="template"
>

</form>

</section>

</main>


<script>

const METHOD = <?= json_encode($method) ?>;

const SETUP_MODE = <?= json_encode($setup) ?>;

const CSRF = <?= json_encode($csrf) ?>;

const CURRENT_NAME = <?= json_encode(current_user()['name'] ?? '') ?>;

const SERVER_ERROR = <?= json_encode($_GET['error'] ?? '') ?>;

const title =
    document.getElementById('title');

const statusEl =
    document.getElementById('status');

const warning =
    document.getElementById('secureWarning');

const email =
    document.getElementById('email');

const emailValue =
    document.getElementById('emailValue');

const template =
    document.getElementById('template');

const form =
    document.getElementById('authForm');

const video =
    document.getElementById('video');

const cameraBox =
    document.getElementById('cameraBox');

const voiceBox =
    document.getElementById('voiceBox');

const fingerprintBox =
    document.getElementById('fingerprintBox');

const start =
    document.getElementById('start');

const stop =
    document.getElementById('stop');

const levelBar =
    document.getElementById('levelBar');

let cameraStream = null;

let microphoneStream = null;

let audioContext = null;

let analyser = null;

let detectionTimer = null;

let detecting = false;

let faceModelsLoaded = false;


/* --------------------------------------------------
   TÍTULO
-------------------------------------------------- */

const titles = {

    face:
        '👤 Reconocimiento facial',

    voice:
        '🎤 Reconocimiento de voz',

    fingerprint:
        '👆 Huella digital / Passkey'

};

title.textContent =
    titles[METHOD];

if (SETUP_MODE && METHOD !== 'fingerprint') {
    // Registro desde el panel: se usa el nombre de la sesión
    email.value = CURRENT_NAME;
    email.closest('.field').classList.add('hidden');

    if (METHOD === 'face') {
        title.textContent = '👤 Registrar rostro';
        start.textContent = 'Registrar rostro';
    } else {
        title.textContent = '🎤 Registrar voz';
        start.textContent = 'Registrar voz';
    }
}

if (METHOD === 'fingerprint') {
    if (SETUP_MODE) {
        // Ya hay sesión: no hace falta escribir el nombre
        email.closest('.field').classList.add('hidden');

        title.textContent = '👆 Registrar huella / Passkey';
        start.textContent = 'Registrar huella';
    }
}



/* --------------------------------------------------
   HOST SEGURO
-------------------------------------------------- */

function isLocalhost() {

    return [
        'localhost',
        '127.0.0.1',
        '::1'
    ].includes(
        location.hostname
    );

}


function secureMediaAvailable() {

    if (isLocalhost()) {
        return true;
    }

    return window.isSecureContext === true;

}


/* --------------------------------------------------
   COMPROBAR DISPOSITIVO
-------------------------------------------------- */

function checkDevice() {

    if (
        METHOD === 'face' ||
        METHOD === 'voice'
    ) {

        if (!secureMediaAvailable()) {

            warning.classList.remove(
                'hidden'
            );

            warning.innerHTML = `
                <strong>Se requiere HTTPS.</strong><br><br>

                En el celular, la cámara y el micrófono
                no pueden utilizarse normalmente desde
                una dirección HTTP como
                <code>192.168.x.x</code>.

                <br><br>

                Abre este proyecto mediante HTTPS.
            `;

            statusEl.textContent =
                'La cámara/micrófono requieren HTTPS.';

            start.disabled = true;

            return false;
        }

        if (
            !navigator.mediaDevices ||
            !navigator.mediaDevices.getUserMedia
        ) {

            statusEl.textContent =
                'Tu navegador no permite cámara/micrófono.';

            start.disabled = true;

            return false;
        }

    }

    return true;
}


/* --------------------------------------------------
   MODELOS FACIALES
-------------------------------------------------- */

async function loadFaceModels() {

    if (faceModelsLoaded) {
        return;
    }

    const MODEL_URL =
        'https://justadudewhohacks.github.io/face-api.js/models';

    statusEl.textContent =
        'Cargando reconocimiento facial...';

    await Promise.all([

        faceapi.nets.tinyFaceDetector
            .loadFromUri(MODEL_URL),

        faceapi.nets.faceLandmark68Net
            .loadFromUri(MODEL_URL),

        faceapi.nets.faceRecognitionNet
            .loadFromUri(MODEL_URL)

    ]);

    faceModelsLoaded = true;
}


/* --------------------------------------------------
   RECONOCIMIENTO FACIAL
-------------------------------------------------- */

async function startFace() {

    if (!checkDevice()) {
        return;
    }

    start.disabled = true;

    try {

        statusEl.textContent =
            'Solicitando acceso a la cámara...';

        cameraStream =
            await navigator.mediaDevices.getUserMedia({

                video: {

                    facingMode: {
                        ideal: 'user'
                    },

                    width: {
                        ideal: 640
                    },

                    height: {
                        ideal: 480
                    },

                    frameRate: {
                        ideal: 30,
                        max: 30
                    }

                },

                audio: false

            });


        video.srcObject =
            cameraStream;


        await video.play();


        await loadFaceModels();


        statusEl.textContent =
            'Mira directamente a la cámara...';


        detectionTimer =
            setInterval(async () => {

                if (detecting) {
                    return;
                }

                detecting = true;

                try {

                    const result =
                        await faceapi
                            .detectSingleFace(
                                video,
                                new faceapi
                                    .TinyFaceDetectorOptions({

                                        inputSize: 224,

                                        scoreThreshold: 0.5

                                    })
                            )
                            .withFaceLandmarks()
                            .withFaceDescriptor();


                    if (!result) {

                        statusEl.textContent =
                            'No se detecta un rostro.';

                        return;
                    }


                    statusEl.textContent =
                        'Rostro detectado. Verificando...';


                    template.value =
                        JSON.stringify(
                            Array.from(
                                result.descriptor
                            )
                        );


                    submitAuthentication();

                }

                catch (error) {

                    console.error(error);

                    statusEl.textContent =
                        'Error durante la detección.';

                }

                finally {

                    detecting = false;

                }

            }, 300);

    }

    catch (error) {

        start.disabled = false;

        showCameraError(error);

    }

}


/* --------------------------------------------------
   VOZ
-------------------------------------------------- */

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}


/* Filtros mel: imitan cómo el oído agrupa las frecuencias */
function buildMelFilters(sampleRate, fftSize) {

    const MELS = 26;
    const LOW = 100;
    const HIGH = Math.min(4500, sampleRate / 2);

    const toMel = f => 2595 * Math.log10(1 + f / 700);
    const toHz = m => 700 * (Math.pow(10, m / 2595) - 1);

    const points = [];

    for (let i = 0; i <= MELS + 1; i++) {
        points.push(
            toHz(toMel(LOW) + (toMel(HIGH) - toMel(LOW)) * i / (MELS + 1))
        );
    }

    const binHz = sampleRate / fftSize;
    const filters = [];

    for (let m = 1; m <= MELS; m++) {

        const left = points[m - 1];
        const center = points[m];
        const right = points[m + 1];

        const from = Math.max(1, Math.floor(left / binHz));
        const to = Math.ceil(right / binHz);

        const weights = [];

        for (let k = from; k <= to; k++) {

            const f = k * binHz;
            let value = 0;

            if (f >= left && f <= center) {
                value = (f - left) / (center - left);
            } else if (f > center && f <= right) {
                value = (right - f) / (right - center);
            }

            weights.push(value);
        }

        filters.push({ from, weights });
    }

    return filters;
}


/* 12 coeficientes MFCC de un cuadro (sin el 0: así el volumen no influye) */
function computeMfcc(freqData, filters) {

    const logEnergies = filters.map(({ from, weights }) => {

        let energy = 0;

        for (let j = 0; j < weights.length; j++) {
            const db = Math.max(freqData[from + j], -120);
            energy += weights[j] * Math.pow(10, db / 10);
        }

        return Math.log(energy + 1e-12);
    });

    const M = logEnergies.length;
    const coefficients = [];

    for (let k = 1; k <= 12; k++) {

        let sum = 0;

        for (let m = 0; m < M; m++) {
            sum += logEnergies[m] * Math.cos(Math.PI * k * (m + 0.5) / M);
        }

        coefficients.push(sum * Math.sqrt(2 / M));
    }

    return coefficients;
}


/* Tono (frecuencia fundamental) por autocorrelación; null si no hay voz clara */
function estimatePitch(timeData, sampleRate) {

    const minLag = Math.floor(sampleRate / 350);
    const maxLag = Math.floor(sampleRate / 70);

    let energy = 0;

    for (let i = 0; i < timeData.length; i++) {
        energy += timeData[i] * timeData[i];
    }

    if (energy === 0) {
        return null;
    }

    let best = 0;
    let bestLag = 0;

    for (let lag = minLag; lag <= maxLag; lag++) {

        let sum = 0;

        for (let i = 0; i < timeData.length - lag; i += 2) {
            sum += timeData[i] * timeData[i + lag];
        }

        if (sum > best) {
            best = sum;
            bestLag = lag;
        }
    }

    if (!bestLag || (best * 2) / energy < 0.3) {
        return null;
    }

    return sampleRate / bestLag;
}


/* Graba una muestra y devuelve 25 valores */
function captureVoiceSample(durationMs) {

    return new Promise((resolve, reject) => {

        const sampleRate = audioContext.sampleRate;
        const timeData = new Float32Array(analyser.fftSize);
        const freqData = new Float32Array(analyser.frequencyBinCount);
        const filters = buildMelFilters(sampleRate, analyser.fftSize);

        const frames = [];
        const pitches = [];
        let counter = 0;
        const startTime = performance.now();

        function tick() {

            if (!analyser) {
                reject({ cancelled: true });
                return;
            }

            analyser.getFloatTimeDomainData(timeData);

            let energy = 0;

            for (let i = 0; i < timeData.length; i++) {
                energy += timeData[i] * timeData[i];
            }

            const rms = Math.sqrt(energy / timeData.length);

            levelBar.style.width = Math.min(100, rms * 400) + '%';

            // Solo cuentan los momentos en que realmente hay voz
            if (rms > 0.01) {

                analyser.getFloatFrequencyData(freqData);
                frames.push(computeMfcc(freqData, filters));

                if (counter++ % 2 === 0) {
                    const f0 = estimatePitch(timeData, sampleRate);

                    if (f0) {
                        pitches.push(f0);
                    }
                }
            }

            if (performance.now() - startTime < durationMs) {
                requestAnimationFrame(tick);
                return;
            }

            if (frames.length < 30) {
                reject({ userMessage: 'No se escuchó tu voz. Habla más fuerte y cerca del micrófono.' });
                return;
            }

            if (pitches.length < 8) {
                reject({ userMessage: 'No se detectó el tono de tu voz. Habla con voz normal, sin susurrar.' });
                return;
            }

            const mean = [];
            const spread = [];

            for (let k = 0; k < 12; k++) {

                const values = frames.map(frame => frame[k]);
                const avg = values.reduce((a, b) => a + b, 0) / values.length;

                const variance =
                    values.reduce((a, b) => a + (b - avg) * (b - avg), 0) /
                    values.length;

                mean.push(avg);
                spread.push(Math.sqrt(variance));
            }

            pitches.sort((a, b) => a - b);

            const medianPitch = pitches[Math.floor(pitches.length / 2)];
            const semitones = 12 * Math.log2(medianPitch / 100);

            resolve(
                [...mean, ...spread, semitones].map(v => Number(v.toFixed(4)))
            );
        }

        tick();
    });
}


async function startVoice() {

    if (!checkDevice()) {
        return;
    }

    start.disabled = true;

    cameraBox.classList.add('hidden');
    voiceBox.classList.remove('hidden');

    try {

        statusEl.textContent = 'Solicitando micrófono...';

        // Sin filtros del navegador: alteran el timbre y dan capturas inconsistentes
        microphoneStream =
            await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: false,
                    noiseSuppression: false,
                    autoGainControl: false
                },
                video: false
            });

        audioContext =
            new (window.AudioContext || window.webkitAudioContext)();

        await audioContext.resume();

        const source =
            audioContext.createMediaStreamSource(microphoneStream);

        analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        analyser.smoothingTimeConstant = 0;

        source.connect(analyser);

        // Al registrar se graban 3 muestras; al iniciar sesión, 1
        const rounds = SETUP_MODE ? 3 : 1;
        const all = [];

        for (let round = 1; round <= rounds; round++) {

            const prefix =
                rounds > 1 ? 'Grabación ' + round + ' de ' + rounds + '. ' : '';

            statusEl.textContent = prefix + 'Prepárate...';

            await sleep(round === 1 ? 1200 : 1500);

            if (!analyser) {
                return;
            }

            statusEl.textContent = prefix + 'Di: "mi voz es mi contraseña"';

            const sample = await captureVoiceSample(3000);

            all.push(...sample);
        }

        template.value = JSON.stringify(all);

        statusEl.textContent = 'Muestra capturada. Verificando...';

        submitAuthentication();

    }

    catch (error) {

        start.disabled = false;

        if (error && error.cancelled) {
            return;
        }

        if (error && error.userMessage) {
            cleanup();
            statusEl.textContent = error.userMessage;
            return;
        }

        showMicrophoneError(error);

    }

}


/* --------------------------------------------------
   WEBAUTHN
-------------------------------------------------- */

async function webauthnPost(url, payload) {

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, csrf: CSRF })
    });

    let data = null;

    try {
        data = await response.json();
    } catch (e) {}

    if (!response.ok || !data || !data.success) {
        throw new Error(
            (data && data.message) ||
            'Error del servidor (' + response.status + ').'
        );
    }

    return data;
}


async function registerPasskey() {

    statusEl.textContent = 'Preparando registro...';

    const { publicKey } = await webauthnPost(
        'webauthn_options.php',
        { action: 'register' }
    );

    publicKey.challenge = base64ToArrayBuffer(publicKey.challenge);
    publicKey.user.id = base64ToArrayBuffer(publicKey.user.id);

    (publicKey.excludeCredentials || []).forEach(
        c => c.id = base64ToArrayBuffer(c.id)
    );

    statusEl.textContent = 'Confirma con tu huella, rostro o PIN...';

    const credential = await navigator.credentials.create({ publicKey });

    if (!credential) {
        throw new Error('No se recibió la credencial.');
    }

    statusEl.textContent = 'Guardando huella...';

    await webauthnPost('webauthn_verify.php', {
        action: 'register',
        credential: {
            id: credential.id,
            rawId: arrayBufferToBase64(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:
                    arrayBufferToBase64(credential.response.clientDataJSON),
                attestationObject:
                    arrayBufferToBase64(credential.response.attestationObject)
            }
        }
    });

    statusEl.textContent = '¡Huella registrada! Redirigiendo...';

    setTimeout(() => { location.href = 'dashboard.php'; }, 1200);
}


async function loginWithPasskey() {

    statusEl.textContent = 'Preparando autenticación...';

    const { publicKey } = await webauthnPost(
        'webauthn_options.php',
        { action: 'login', name: email.value.trim() }
    );

    publicKey.challenge = base64ToArrayBuffer(publicKey.challenge);

    (publicKey.allowCredentials || []).forEach(
        c => c.id = base64ToArrayBuffer(c.id)
    );

    statusEl.textContent = 'Usa tu huella, rostro o PIN...';

    const credential = await navigator.credentials.get({ publicKey });

    if (!credential) {
        throw new Error('No se recibió la credencial.');
    }

    statusEl.textContent = 'Verificando...';

    const result = await webauthnPost('webauthn_verify.php', {
        action: 'login',
        credential: {
            id: credential.id,
            rawId: arrayBufferToBase64(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:
                    arrayBufferToBase64(credential.response.clientDataJSON),
                authenticatorData:
                    arrayBufferToBase64(credential.response.authenticatorData),
                signature:
                    arrayBufferToBase64(credential.response.signature),
                userHandle: credential.response.userHandle
                    ? arrayBufferToBase64(credential.response.userHandle)
                    : null
            }
        }
    });

    location.href = result.redirect || 'dashboard.php';
}


async function startFingerprint() {

    cameraBox.classList.add('hidden');
    voiceBox.classList.add('hidden');
    fingerprintBox.classList.remove('hidden');

    if (!window.isSecureContext) {
        throw new Error(
            'WebAuthn requiere HTTPS (o localhost). Abre el sitio con un enlace https://.'
        );
    }

    if (!window.PublicKeyCredential) {
        throw new Error('Este navegador no soporta WebAuthn.');
    }

    start.disabled = true;

    try {

        if (SETUP_MODE) {
            await registerPasskey();
        } else {
            await loginWithPasskey();
        }

    } catch (error) {

        start.disabled = false;

        if (error.name === 'NotAllowedError') {
            throw new Error('Operación cancelada o tiempo agotado. Inténtalo de nuevo.');
        }

        if (error.name === 'InvalidStateError') {
            throw new Error('Este dispositivo ya tiene una huella registrada para tu cuenta.');
        }

        throw error;
    }
}


/* --------------------------------------------------
   BASE64
-------------------------------------------------- */

function arrayBufferToBase64(
    buffer
) {

    const bytes =
        new Uint8Array(buffer);

    let binary = '';

    bytes.forEach(
        byte =>
            binary +=
            String.fromCharCode(byte)
    );

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=/g, '');

}


function base64ToArrayBuffer(
    value
) {

    value =
        value
            .replace(/-/g, '+')
            .replace(/_/g, '/');

    while (
        value.length % 4
    ) {

        value += '=';

    }

    const binary =
        atob(value);

    const bytes =
        new Uint8Array(
            binary.length
        );

    for (
        let i = 0;
        i < binary.length;
        i++
    ) {

        bytes[i] =
            binary.charCodeAt(i);

    }

    return bytes.buffer;

}


/* --------------------------------------------------
   ERRORES
-------------------------------------------------- */

function showCameraError(error) {

    console.error(error);

    let message =
        'No fue posible acceder a la cámara.';

    if (
        error.name ===
        'NotAllowedError'
    ) {

        message =
            'Permiso de cámara rechazado. Activa la cámara para este sitio.';

    }

    if (
        error.name ===
        'NotFoundError'
    ) {

        message =
            'No se encontró ninguna cámara.';

    }

    if (
        error.name ===
        'NotReadableError'
    ) {

        message =
            'La cámara está siendo utilizada por otra aplicación.';

    }

    statusEl.textContent =
        message;

}


function showMicrophoneError(error) {

    console.error(error);

    let message =
        'No fue posible acceder al micrófono.';

    if (
        error.name ===
        'NotAllowedError'
    ) {

        message =
            'Permiso de micrófono rechazado. Activa el micrófono para este sitio.';

    }

    if (
        error.name ===
        'NotFoundError'
    ) {

        message =
            'No se encontró ningún micrófono.';

    }

    statusEl.textContent =
        message;

}


/* --------------------------------------------------
   LIMPIAR RECURSOS
-------------------------------------------------- */

function cleanup() {

    if (detectionTimer) {

        clearInterval(
            detectionTimer
        );

        detectionTimer = null;

    }


    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(
                track =>
                    track.stop()
            );

        cameraStream = null;

    }


    if (microphoneStream) {

        microphoneStream
            .getTracks()
            .forEach(
                track =>
                    track.stop()
            );

        microphoneStream = null;

    }


    if (audioContext) {

        audioContext
            .close()
            .catch(
                () => {}
            );

        audioContext = null;

    }


    analyser = null;

}


/* --------------------------------------------------
   ENVIAR AUTENTICACIÓN
-------------------------------------------------- */

async function submitAuthentication() {

    cleanup();

    emailValue.value =
        email.value.trim();

    // Registro desde el panel: se envía sin recargar y se muestra el error real
    if (SETUP_MODE && METHOD !== 'fingerprint') {

        statusEl.textContent = 'Guardando...';

        try {

            const body = new URLSearchParams({
                csrf: CSRF,
                method: METHOD,
                setup: '1',
                template: template.value
            });

            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });

            let data = null;

            try {
                data = await response.json();
            } catch (e) {}

            if (!response.ok || !data || !data.success) {
                throw new Error(
                    (data && data.message) ||
                    'Error del servidor (' + response.status + ').'
                );
            }

            statusEl.textContent = data.message;

            setTimeout(() => {
                location.href =
                    'dashboard.php?ok=' + encodeURIComponent(data.message);
            }, 800);

        } catch (error) {

            start.disabled = false;

            statusEl.textContent = 'Error: ' + error.message;
        }

        return;
    }

    form.submit();

}


/* --------------------------------------------------
   BOTONES
-------------------------------------------------- */

start.addEventListener(
    'click',
    async () => {

        try {

            if (
                (METHOD !== 'fingerprint' || !SETUP_MODE) &&
                !email.value.trim()
            ) {

                statusEl.textContent =
                    METHOD === 'face'
                        ? 'Escribe primero tu nombre.'
                        : 'Escribe primero tu nombre.';

                return;

            }


            if (
                METHOD === 'face'
            ) {

                await startFace();

            }

            else if (
                METHOD === 'voice'
            ) {

                await startVoice();

            }

            else {

                await startFingerprint();

            }

        }

        catch (error) {

            console.error(error);

            cleanup();

            start.disabled = false;

            statusEl.textContent =
                'Error: ' +
                (
                    error.message ||
                    error
                );

        }

    }
);


stop.addEventListener(
    'click',
    () => {

        cleanup();

        start.disabled = false;

        statusEl.textContent =
            'Proceso detenido.';

    }
);


/* --------------------------------------------------
   INICIO
-------------------------------------------------- */

checkDevice();

if (SERVER_ERROR) {
    statusEl.textContent = SERVER_ERROR;
}

</script>

</body>

</html>