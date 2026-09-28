<?php

require __DIR__ . '/../config/config.php';

verify_csrf();

/*
 * Voz: vector de 24 bandas de frecuencia (timbre).
 * VOICE_MAX_DISTANCE = diferencia promedio máxima en dB entre la voz
 * guardada y la nueva. Más bajo = más estricto. Calíbralo con pruebas
 * (la distancia real queda anotada en login_attempts.reason).
 */
const VOICE_DIMS = 24;
const VOICE_MAX_DISTANCE = 6.0;


/*
 * REGISTRO desde el panel (rostro / voz).
 * Responde JSON para poder mostrar el error exacto sin recargar.
 */

if (($_POST['setup'] ?? '') === '1') {

    header('Content-Type: application/json; charset=utf-8');

    $fail = function (string $message, int $status = 400): void {
        http_response_code($status);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    };

    $current = current_user();
    $setupMethod = $_POST['method'] ?? '';

    if (!$current) {
        $fail('Tu sesión expiró. Inicia sesión otra vez.', 401);
    }

    $expected = $setupMethod === 'face' ? 128 : ($setupMethod === 'voice' ? VOICE_DIMS : 0);
    $values = json_decode((string)($_POST['template'] ?? ''), true);

    if ($expected === 0) {
        $fail('Método inválido.');
    }

    if (!is_array($values) || count($values) !== $expected) {
        $fail('No se pudieron leer los datos (se esperaban ' . $expected . ' valores). Inténtalo de nuevo.');
    }

    $clean = [];

    foreach ($values as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            $fail('Los datos capturados no son válidos. Inténtalo de nuevo.');
        }

        $clean[] = (float)$value;
    }

    $column = $setupMethod === 'face' ? 'face_template' : 'voice_template';

    $update = db()->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
    $update->execute([
        json_encode($clean, JSON_THROW_ON_ERROR),
        (int)$current['id']
    ]);

    log_attempt((int)$current['id'], $setupMethod . '-register', true, null);

    echo json_encode([
        'success' => true,
        'message' => $setupMethod === 'face'
            ? 'Rostro registrado correctamente.'
            : 'Voz registrada correctamente.'
    ]);
    exit;
}

$method =
    $_POST['method'] ?? '';

$usesName = in_array($method, ['face', 'voice'], true);

$identity =
    trim($_POST['email'] ?? '');

$email =
    $usesName
        ? null
        : filter_var($identity, FILTER_VALIDATE_EMAIL);

$template =
    trim(
        $_POST['template'] ?? ''
    );


/*
 * Validaciones
 */

if (
    !in_array($method, ['face', 'voice', 'fingerprint'], true) ||
    $template === '' ||
    ($usesName && ($identity === '' || strlen($identity) > 120)) ||
    (!$usesName && !$email)
) {

    header(
        'Location: index.php?error=' .
        urlencode(
            'Datos de autenticación inválidos.'
        )
    );

    exit;
}


$incomingTemplate = null;

if ($usesName) {
    $decodedTemplate = json_decode($template, true);
    $expectedValues = $method === 'face' ? 128 : VOICE_DIMS;

    if (!is_array($decodedTemplate) || count($decodedTemplate) !== $expectedValues) {
        header('Location: biometric.php?method=' . urlencode($method) . '&error=' . urlencode('No se pudieron leer los datos biométricos. Inténtalo de nuevo.'));
        exit;
    }

    $incomingTemplate = [];

    foreach ($decodedTemplate as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            header('Location: biometric.php?method=' . urlencode($method) . '&error=' . urlencode('Los datos biométricos no son válidos.'));
            exit;
        }

        $incomingTemplate[] = (float)$value;
    }
}


/*
 * Buscar usuario
 */

$pdo = db();
$templateColumns = [
    'face' => 'face_template',
    'voice' => 'voice_template'
];

$stmt = $pdo->prepare(
    $usesName
        ? 'SELECT * FROM users WHERE name = ? LIMIT 1'
        : 'SELECT * FROM users WHERE email = ? LIMIT 1'
);

$stmt->execute([
    $usesName ? $identity : $email
]);

$user =
    $stmt->fetch();

if ($usesName && !$user) {
    $emailPrefix = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '.', $identity));
    $emailPrefix = trim(substr($emailPrefix, 0, 80), '.');
    $generatedEmail = ($emailPrefix !== '' ? $emailPrefix : 'usuario') . '.' . bin2hex(random_bytes(6)) . '@local.test';
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $templateJson = json_encode($incomingTemplate, JSON_THROW_ON_ERROR);
    $templateColumn = $templateColumns[$method];

    $insert = $pdo->prepare(
        "INSERT INTO users
         (name, email, password_hash, role, active, {$templateColumn})
         VALUES (?, ?, ?, 'Gestor', 1, ?)"
    );
    $insert->execute([$identity, $generatedEmail, $passwordHash, $templateJson]);

    $userId = (int)$pdo->lastInsertId();
    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
}


$success = false;

$reason =
    'Autenticación fallida';


/*
 * Usuario válido
 */

if (
    $user &&
    (int)$user['active'] === 1
) {

    /*
     * RECONOCIMIENTO FACIAL
     */

    if (
        $method === 'face'
    ) {

        $incoming = $incomingTemplate;

        $stored =
            json_decode(
                $user['face_template'] ?? '',
                true
            );


        if (is_array($incoming) && (!is_array($stored) || count($stored) !== 128)) {
            if ((int)(current_user()['id'] ?? 0) === (int)$user['id']) {
                $update = $pdo->prepare('UPDATE users SET face_template = ? WHERE id = ?');
                $update->execute([
                    json_encode($incoming, JSON_THROW_ON_ERROR),
                    (int)$user['id']
                ]);

                $success = true;
                $reason = null;
            } else {
                $reason = 'Este usuario aún no tiene un rostro registrado.';
            }
        } elseif (
            is_array($incoming) &&
            is_array($stored) &&
            count($incoming) === count($stored)
        ) {

            $sum = 0;

            foreach (
                $incoming as $index => $value
            ) {

                $difference =
                    (float)$value -
                    (float)($stored[$index] ?? 0);

                $sum +=
                    $difference *
                    $difference;

            }


            $distance =
                sqrt($sum);


            /*
             * Umbral de ejemplo.
             * Debe calibrarse con datos de prueba.
             */

            if (
                $distance < 0.55
            ) {

                $success = true;

                $reason =
                    null;

            }

        }

    }


    /*
     * RECONOCIMIENTO DE VOZ
     */

    elseif (
        $method === 'voice'
    ) {

        $incoming = $incomingTemplate;

        $stored =
            json_decode(
                $user['voice_template'] ?? '',
                true
            );


        if (is_array($incoming) && (!is_array($stored) || count($stored) !== VOICE_DIMS)) {
            if ((int)(current_user()['id'] ?? 0) === (int)$user['id']) {
                $update = $pdo->prepare('UPDATE users SET voice_template = ? WHERE id = ?');
                $update->execute([
                    json_encode($incoming, JSON_THROW_ON_ERROR),
                    (int)$user['id']
                ]);

                $success = true;
                $reason = null;
            } else {
                $reason = 'Este usuario aún no tiene una voz registrada.';
            }
        } elseif (
            is_array($incoming) &&
            is_array($stored) &&
            count($incoming) === VOICE_DIMS &&
            count($stored) === VOICE_DIMS
        ) {

            // Diferencia promedio (RMS) por banda, en dB
            $sum = 0.0;

            for ($i = 0; $i < VOICE_DIMS; $i++) {
                $difference = (float)$incoming[$i] - (float)$stored[$i];
                $sum += $difference * $difference;
            }

            $distance = sqrt($sum / VOICE_DIMS);

            if ($distance < VOICE_MAX_DISTANCE) {
                $success = true;
                $reason = null;
            } else {
                $reason = 'Voz no coincide (distancia ' . round($distance, 2) . ')';
            }

        } elseif (is_array($stored) && count($stored) !== VOICE_DIMS) {

            $reason = 'Tu voz se registró con una versión anterior. Regístrala de nuevo.';

        }

    }


    /*
     * WEBAUTHN
     */

    elseif (
        $method === 'fingerprint'
    ) {

        /*
         * IMPORTANTE:
         *
         * Aquí NO se debe confiar simplemente
         * en los datos enviados por JavaScript.
         *
         * La implementación real necesita:
         *
         * 1. challenge
         * 2. credential ID
         * 3. clave pública
         * 4. clientDataJSON
         * 5. authenticatorData
         * 6. signature
         * 7. validación de RP ID
         * 8. validación de origin
         * 9. contador del autenticador
         *
         * Por seguridad no se considera autenticado
         * solamente por recibir el JSON.
         */

        $success = false;

        $reason =
            'WebAuthn requiere validación criptográfica del servidor.';

    }

}


/*
 * Registrar intento
 */

log_attempt(

    $user['id'] ?? null,

    $method,

    $success,

    $_SERVER['REMOTE_ADDR'] ??
    'unknown',

    $reason

);


/*
 * Autenticación correcta
 */

if ($success) {

    session_regenerate_id(true);

    $_SESSION['user'] = [

        'id' =>
            (int)$user['id'],

        'name' =>
            $user['name'],

        'email' =>
            $user['email'],

        'role' =>
            $user['role']

    ];


    header(
        'Location: dashboard.php'
    );

    exit;
}


/*
 * Autenticación incorrecta
 */

header(
    'Location: index.php?error=' .
    urlencode(
        'Acceso no autorizado. La autenticación falló.'
    )
);

exit;