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

    <title>Auth PHP Académico</title>

    <link
        rel="stylesheet"
        href="assets/style.css"
    >

</head>

<body>

<main class="container">

    <section class="card">

        <h1>🔐 Auth PHP Académico</h1>

        <p class="subtitle">
            Selecciona un método de autenticación
        </p>

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
                <span class="icon">👤</span>
                <strong>Reconocimiento facial</strong>
                <small>
                    Utiliza la cámara
                </small>
            </a>

            <a
                class="method"
                href="biometric.php?method=voice"
            >
                <span class="icon">🎤</span>
                <strong>Reconocimiento de voz</strong>
                <small>
                    Utiliza el micrófono
                </small>
            </a>

            <a
                class="method"
                href="biometric.php?method=fingerprint"
            >
                <span class="icon">👆</span>
                <strong>Huella digital</strong>
                <small>
                    WebAuthn / Passkey
                </small>
            </a>

            <a
                class="method"
                href="login.php?method=pattern"
            >
                <span class="icon">🔢</span>
                <strong>Patrón de acceso</strong>
                <small>
                    Dibuja tu patrón
                </small>
            </a>

        </div>


    </section>

</main>

</body>
</html>