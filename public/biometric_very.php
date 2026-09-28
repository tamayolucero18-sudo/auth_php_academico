<?php

require __DIR__ . '/../config/config.php';

verify_csrf();

/*
 * VOZ
 * Cada muestra = 25 valores: 12 MFCC (media) + 12 MFCC (variación) + tono.
 * Al registrarse se graban 3 muestras: se guarda su promedio (25 valores)
 * y una tolerancia propia del usuario (valor 26).
 */
const VOICE_DIMS = 25;
const VOICE_ENROLL_SAMPLES = 3;
const VOICE_MIN_LIMIT = 1.5;          // límite mínimo de aceptación
const VOICE_MAX_ABS = 5.0;            // límite máximo, aunque el registro haya sido flojo
const VOICE_TOL_FACTOR = 1.6;         // margen sobre la variación propia del usuario
const VOICE_MAX_ENROLL_SPREAD = 4.0;  // si las 3 grabaciones difieren más, se rechaza el registro

function voice_distance(array $a, array $b): float
{
    $mean = 0.0;
    $spread = 0.0;

    for ($i = 0; $i < 12; $i++) {
        $d = (float)$a[$i] - (float)$b[$i];
        $mean += $d * $d;
    }

    for ($i = 12; $i < 24; $i++) {
        $d = (float)$a[$i] - (float)$b[$i];
        $spread += $d * $d;
    }

    $pitch = abs((float)$a[24] - (float)$b[24]); // semitonos

    return sqrt($mean / 12) + 0.5 * sqrt($spread / 12) + 0.3 * $pitch;
}


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

    $expected = $setupMethod === 'face' ? 128 : ($setupMethod === 'voice' ? VOICE_DIMS * VOICE_ENROLL_SAMPLES : 0);
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

    $toStore = $clean;

    if ($setupMethod === 'voice') {

        $samples = array_chunk($clean, VOICE_DIMS);
        $count = count($samples);

        $average = array_fill(0, VOICE_DIMS, 0.0);

        foreach ($samples as $sample) {
            foreach ($sample as $i => $value) {
                $average[$i] += $value / $count;
            }
        }

        $tolerance = 0.0;

        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $tolerance = max($tolerance, voice_distance($samples[$a], $samples[$b]));
            }
        }

        if ($tolerance > VOICE_MAX_ENROLL_SPREAD) {
            $fail('Las 3 grabaciones fueron muy distintas entre sí. Repite la misma frase igual las 3 veces, en un lugar silencioso.');
        }

        $average[] = $tolerance;
        $toStore = $average;
    }

    $column = $setupMethod === 'face' ? 'face_template' : 'voice_template';

    $update = db()->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
    $update->execute([
        json_encode($toStore, JSON_THROW_ON_ERROR),
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


        if (!is_array($stored) || count($stored) !== VOICE_DIMS + 1) {

            $reason = 'Este usuario no tiene una voz registrada (o es de una versión anterior). Regístrala desde el panel.';

        } elseif (is_array($incoming) && count($incoming) === VOICE_DIMS) {

            $distance = voice_distance($incoming, $stored);
            $tolerance = (float)$stored[VOICE_DIMS];

            // Límite propio del usuario, acotado entre un mínimo y un máximo
            $limit = min(
                VOICE_MAX_ABS,
                max(VOICE_MIN_LIMIT, $tolerance * VOICE_TOL_FACTOR)
            );

            $detail = 'distancia ' . round($distance, 2) . ', límite ' . round($limit, 2);

            if ($distance < $limit) {
                $success = true;
                $reason = 'Voz coincide (' . $detail . ')';
            } else {
                $reason = 'Voz no coincide (' . $detail . ')';
            }

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