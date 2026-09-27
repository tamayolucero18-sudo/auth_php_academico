<?php
require __DIR__ . '/../config/config.php';

$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Sistema de Autenticación Multimodal</title>

    <link
        rel="stylesheet"
        href="assets/style.css"
    >
    <link
        rel="stylesheet"
        href="assets/landing.css"
    >

</head>

<body class="landing-page">

<main class="container">

    <section class="card landing-card">

        <header class="landing-header">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none">
                    <path d="M16 3.5 27 8v7.2c0 6.3-4.5 10.8-11 13.3C9.5 26 5 21.5 5 15.2V8l11-4.5Z" />
                    <path d="m11.5 15.8 3 3 6-6.2" />
                </svg>
            </span>
            <div class="brand-copy">
                <span class="eyebrow">IDENTIDAD DIGITAL <span>•</span> ACCESO SEGURO</span>
                <h1>Sistema de Autenticación Multimodal</h1>
            </div>
        </header>

        <div class="section-heading">
            <div>
                <p class="subtitle">Selecciona cómo quieres verificar tu identidad.</p>
            </div>
            <span class="secure-label">
                <span class="secure-dot"></span>
                SISTEMA ACTIVO
            </span>
        </div>

        <?php if ($error): ?>

            <div class="alert danger">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <div class="methods">

            <a
                class="method"
                href="biometric.php?method=face"
            >
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M11 4H6a2 2 0 0 0-2 2v5M21 4h5a2 2 0 0 1 2 2v5M28 21v5a2 2 0 0 1-2 2h-5M11 28H6a2 2 0 0 1-2-2v-5" />
                        <circle cx="16" cy="13" r="4" />
                        <path d="M9.5 24c.8-3.2 3.1-5 6.5-5s5.7 1.8 6.5 5" />
                    </svg>
                </span>
                <span class="method-copy">
                    <strong>Reconocimiento facial</strong>
                    <small>Verificación mediante cámara</small>
                </span>
                <span class="method-arrow" aria-hidden="true">↗</span>
            </a>

            <a
                class="method"
                href="biometric.php?method=voice"
            >
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <rect x="12" y="4" width="8" height="16" rx="4" />
                        <path d="M8 15v1a8 8 0 0 0 16 0v-1M16 24v4m-5 0h10" />
                        <path d="M5 12v5m22-5v5" />
                    </svg>
                </span>
                <span class="method-copy">
                    <strong>Reconocimiento de voz</strong>
                    <small>Verificación mediante micrófono</small>
                </span>
                <span class="method-arrow" aria-hidden="true">↗</span>
            </a>

            <a
                class="method"
                href="biometric.php?method=fingerprint"
            >
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M8 14a8 8 0 0 1 16 0c0 5.5-1.1 9.6-3.1 13M12 15a4 4 0 0 1 8 0c0 5.1-.7 9.2-2 12M16 15v3c0 3.3-.4 6.2-1.2 8.8M5.5 17.5C5.2 16.2 5 15.1 5 14a11 11 0 0 1 22 0c0 2.2-.2 4.3-.6 6.3M9.1 20c-.4-1.9-.6-3.9-.6-6" />
                    </svg>
                </span>
                <span class="method-copy">
                    <strong>Huella digital</strong>
                    <small>WebAuthn / Passkey del dispositivo</small>
                </span>
                <span class="method-arrow" aria-hidden="true">↗</span>
            </a>

            <a
                class="method"
                href="login.php?method=pattern"
            >
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M8 8 16 16 24 8M8 8l8 16 8-16M8 24h16" />
                        <circle cx="8" cy="8" r="2.5" />
                        <circle cx="16" cy="16" r="2.5" />
                        <circle cx="24" cy="8" r="2.5" />
                        <circle cx="8" cy="24" r="2.5" />
                        <circle cx="24" cy="24" r="2.5" />
                    </svg>
                </span>
                <span class="method-copy">
                    <strong>Patrón de acceso</strong>
                    <small>Secuencia visual personalizada</small>
                </span>
                <span class="method-arrow" aria-hidden="true">↗</span>
            </a>

        </div>


    </section>

</main>

</body>
</html>