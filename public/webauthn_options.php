<?php

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];

verify_csrf((string)($data['csrf'] ?? ''));

$action = $data['action'] ?? '';

try {

    $webAuthn = webauthn();

    /*
     * REGISTRO: solo un usuario con sesión iniciada puede
     * asociar una huella a su cuenta.
     */
    if ($action === 'register') {

        $user = current_user();

        if (!$user) {
            json_out(['success' => false, 'message' => 'Debes iniciar sesión primero.'], 401);
        }

        $stmt = db()->prepare(
            'SELECT credential_id FROM webauthn_credentials WHERE user_id = ?'
        );
        $stmt->execute([(int)$user['id']]);

        $exclude = array_map(
            fn(array $row) => b64u_decode($row['credential_id']),
            $stmt->fetchAll()
        );

        $args = $webAuthn->getCreateArgs(
            (string)$user['id'],
            (string)($user['email'] ?? $user['name']),
            (string)$user['name'],
            60,
            requireResidentKey: false,       // credencial ligada solo a este dispositivo
            requireUserVerification: true,   // exige huella / rostro / PIN
            crossPlatformAttachment: false,  // false = autenticador del propio dispositivo
            excludeCredentialIds: $exclude
        );

        $_SESSION['wa_challenge'] = base64_encode($webAuthn->getChallenge()->getBinaryString());
        $_SESSION['wa_type'] = 'register';
        $_SESSION['wa_time'] = time();

        json_out(['success' => true, 'publicKey' => $args->publicKey]);
    }

    /*
     * LOGIN: se escribe el nombre y se le pide al celular usar
     * SOLO las credenciales registradas para ese usuario.
     */
    if ($action === 'login') {

        $identity = trim((string)($data['name'] ?? ''));

        if ($identity === '' || strlen($identity) > 120) {
            json_out(['success' => false, 'message' => 'Escribe tu nombre de usuario o ID.'], 400);
        }

        $found = find_user_by_identity(db(), $identity);
        if (!$found || (int)$found['active'] !== 1) {
            json_out(['success' => false, 'message' => 'No existe una cuenta activa con ese nombre o ID.'], 404);
        }

        $ids = [];

        $stmt = db()->prepare(
            'SELECT credential_id FROM webauthn_credentials WHERE user_id = ?'
        );
        $stmt->execute([(int)$found['id']]);

        $ids = array_map(
            fn(array $row) => b64u_decode($row['credential_id']),
            $stmt->fetchAll()
        );

        if (!$ids) {
            json_out([
                'success' => false,
                'message' => 'Este usuario no tiene una huella registrada en este sistema.'
            ], 404);
        }

        $args = $webAuthn->getGetArgs($ids, 60, requireUserVerification: true);

        $_SESSION['wa_challenge'] = base64_encode($webAuthn->getChallenge()->getBinaryString());
        $_SESSION['wa_type'] = 'login';
        $_SESSION['wa_time'] = time();
        $_SESSION['wa_user'] = (int)$found['id'];

        json_out(['success' => true, 'publicKey' => $args->publicKey]);
    }

    json_out(['success' => false, 'message' => 'Acción inválida.'], 400);

} catch (Throwable $e) {

    error_log('WebAuthn options: ' . $e->getMessage());

    json_out(['success' => false, 'message' => 'No se pudieron generar las opciones WebAuthn.'], 500);
}