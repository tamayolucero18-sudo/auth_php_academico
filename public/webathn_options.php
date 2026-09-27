<?php

require_once __DIR__ . '/../config/config.php';
$pdo = db();

header('Content-Type: application/json');

$data = json_decode(
    file_get_contents('php://input'),
    true
);

$email = trim(
    $data['email'] ?? ''
);

if ($email === '') {

    echo json_encode([
        'success' => false,
        'message' => 'Correo requerido.'
    ]);

    exit;
}

$stmt = $pdo->prepare("
    SELECT id, email
    FROM users
    WHERE email = ?
      AND active = 1
    LIMIT 1
");

$stmt->execute([$email]);

$user = $stmt->fetch();

if (!$user) {

    echo json_encode([
        'success' => false,
        'message' => 'Usuario no encontrado.'
    ]);

    exit;
}

$challenge =
    random_bytes(32);

$_SESSION['webauthn_challenge'] =
    base64_encode($challenge);

$_SESSION['webauthn_user_id'] =
    $user['id'];

echo json_encode([
    'success' => true,

    'publicKey' => [

        'challenge' =>
            rtrim(
                strtr(
                    base64_encode($challenge),
                    '+/',
                    '-_'
                ),
                '='
            ),

        'timeout' => 60000,

        'rpId' => 'localhost',

        'userVerification' => 'required',

        'allowCredentials' => []
    ]
]);