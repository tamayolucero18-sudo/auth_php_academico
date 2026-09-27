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

if (METHOD === 'fingerprint') {
    document.querySelector('label[for="email"]').textContent =
        'Correo del usuario';
    email.type = 'email';
    email.placeholder = 'usuario@correo.com';
    email.autocomplete = 'email';
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

async function startVoice() {

    if (!checkDevice()) {
        return;
    }

    start.disabled = true;

    cameraBox.classList.add(
        'hidden'
    );

    voiceBox.classList.remove(
        'hidden'
    );


    try {

        statusEl.textContent =
            'Solicitando micrófono...';


        microphoneStream =
            await navigator.mediaDevices
                .getUserMedia({

                    audio: {

                        echoCancellation: true,

                        noiseSuppression: true,

                        autoGainControl: true

                    },

                    video: false

                });


        audioContext =
            new (
                window.AudioContext ||
                window.webkitAudioContext
            )();


        await audioContext.resume();


        const source =
            audioContext
                .createMediaStreamSource(
                    microphoneStream
                );


        analyser =
            audioContext.createAnalyser();


        analyser.fftSize =
            1024;


        source.connect(
            analyser
        );


        const data =
            new Float32Array(
                analyser.fftSize
            );


        const samples = [];

        const startTime =
            performance.now();


        statusEl.textContent =
            'Habla durante 1 segundo...';


        function capture() {

            if (!analyser) {
                return;
            }


            analyser.getFloatTimeDomainData(
                data
            );


            let energy = 0;

            let absolute = 0;

            let zeroCrossings = 0;


            for (
                let i = 0;
                i < data.length;
                i++
            ) {

                energy +=
                    data[i] *
                    data[i];

                absolute +=
                    Math.abs(
                        data[i]
                    );


                if (
                    i > 0 &&
                    data[i] *
                    data[i - 1] < 0
                ) {

                    zeroCrossings++;

                }

            }


            const rms =
                Math.sqrt(
                    energy /
                    data.length
                );


            const mean =
                absolute /
                data.length;


            const zcr =
                zeroCrossings /
                data.length;


            samples.push([
                rms,
                mean,
                zcr
            ]);


            levelBar.style.width =
                Math.min(
                    100,
                    mean * 1000
                ) + '%';


            if (
                performance.now() -
                startTime < 1000
            ) {

                requestAnimationFrame(
                    capture
                );

            }

            else {

                const average =
                    samples
                        .reduce(
                            (total, current) => {

                                return total.map(
                                    (value, index) =>
                                        value +
                                        current[index]
                                );

                            },

                            [0, 0, 0]
                        )
                        .map(
                            value =>
                                value /
                                samples.length
                        );


                template.value =
                    JSON.stringify(
                        average
                    );


                statusEl.textContent =
                    'Muestra capturada. Verificando...';


                submitAuthentication();

            }

        }


        capture();

    }

    catch (error) {

        start.disabled = false;

        showMicrophoneError(error);

    }

}


/* --------------------------------------------------
   WEBAUTHN
-------------------------------------------------- */

async function startFingerprint() {

    cameraBox.classList.add(
        'hidden'
    );

    voiceBox.classList.add(
        'hidden'
    );

    fingerprintBox.classList.remove(
        'hidden'
    );


    if (
        !secureMediaAvailable()
    ) {

        throw new Error(
            'WebAuthn requiere HTTPS cuando no estás en localhost.'
        );

    }


    if (
        !window.PublicKeyCredential
    ) {

        throw new Error(
            'Este navegador no soporta WebAuthn.'
        );

    }


    const userEmail =
        email.value.trim();


    if (!userEmail) {

        throw new Error(
            'Escribe el correo del usuario.'
        );

    }


    statusEl.textContent =
        'Preparando autenticación del dispositivo...';


    const response =
        await fetch(
            'webauthn_options.php?email=' +
            encodeURIComponent(
                userEmail
            )
        );


    if (!response.ok) {

        throw new Error(
            'No se pudieron obtener las opciones WebAuthn.'
        );

    }


    const options =
        await response.json();


    options.challenge =
        base64ToArrayBuffer(
            options.challenge
        );


    if (
        options.allowCredentials
    ) {

        options.allowCredentials =
            options.allowCredentials.map(
                credential => ({

                    ...credential,

                    id:
                        base64ToArrayBuffer(
                            credential.id
                        )

                })
            );

    }


    const credential =
        await navigator.credentials.get({

            publicKey: options

        });


    if (!credential) {

        throw new Error(
            'No se recibió una credencial.'
        );

    }


    template.value =
        JSON.stringify({

            id:
                credential.id,

            rawId:
                arrayBufferToBase64(
                    credential.rawId
                ),

            response: {

                authenticatorData:
                    arrayBufferToBase64(
                        credential
                            .response
                            .authenticatorData
                    ),

                clientDataJSON:
                    arrayBufferToBase64(
                        credential
                            .response
                            .clientDataJSON
                    ),

                signature:
                    arrayBufferToBase64(
                        credential
                            .response
                            .signature
                    )

            },

            type:
                credential.type

        });


    submitAuthentication();

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

function submitAuthentication() {

    cleanup();

    emailValue.value =
        email.value.trim();

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
                !email.value.trim()
            ) {

                statusEl.textContent =
                    METHOD === 'face'
                        ? 'Escribe primero tu nombre.'
                        : 'Escribe primero el correo del usuario.';

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

</script>

</body>

</html>