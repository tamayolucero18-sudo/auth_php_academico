<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

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
$cred   = $data['credential'] ?? [];
$pdo    = db();

/*
 * El challenge es de un solo uso y caduca a los 2 minutos.
 */
$challengeB64 = $_SESSION['wa_challenge'] ?? null;
$challengeType = $_SESSION['wa_type'] ?? null;
$challengeTime = (int)($_SESSION['wa_time'] ?? 0);

$expectedUser = (int)($_SESSION['wa_user'] ?? 0);

unset($_SESSION['wa_challenge'], $_SESSION['wa_type'], $_SESSION['wa_time'], $_SESSION['wa_user']);

if (
    !$challengeB64 ||
    $challengeType !== $action ||
    (time() - $challengeTime) > 120
) {
    json_out(['success' => false, 'message' => 'La solicitud expiró. Inténtalo de nuevo.'], 400);
}

$challenge = new ByteBuffer(base64_decode($challengeB64));

try {

    $webAuthn = webauthn();

    /* ======================================================
     * REGISTRO
     * ==================================================== */
    if ($action === 'register') {

        $user = current_user();

        if (!$user) {
            json_out(['success' => false, 'message' => 'Debes iniciar sesión primero.'], 401);
        }

        $result = $webAuthn->processCreate(
            b64u_decode((string)($cred['response']['clientDataJSON'] ?? '')),
            b64u_decode((string)($cred['response']['attestationObject'] ?? '')),
            $challenge,
            requireUserVerification: true,
            requireUserPresent: true,
            failIfRootMismatch: false
        );

        $insert = $pdo->prepare('
            INSERT INTO webauthn_credentials
                (user_id, credential_id, public_key, sign_count, label)
            VALUES (?, ?, ?, ?, ?)
        ');

        $insert->execute([
            (int)$user['id'],
            b64u_encode($result->credentialId),
            $result->credentialPublicKey,
            (int)($result->signatureCounter ?? 0),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Dispositivo'), 0, 100)
        ]);

        log_attempt((int)$user['id'], 'fingerprint-register', true, null);

        json_out(['success' => true]);
    }

    /* ======================================================
     * LOGIN
     * ==================================================== */
    if ($action === 'login') {

        $credentialId = b64u_encode(b64u_decode((string)($cred['rawId'] ?? '')));

        $stmt = $pdo->prepare('
            SELECT
                c.id AS cred_row_id,
                c.public_key,
                c.sign_count,
                u.*
            FROM webauthn_credentials c
            JOIN users u ON u.id = c.user_id
            WHERE c.credential_id = ?
            LIMIT 1
        ');
        $stmt->execute([$credentialId]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['active'] !== 1) {
            log_attempt(null, 'fingerprint', false, 'Credencial desconocida o usuario inactivo');
            json_out(['success' => false, 'message' => 'Esta huella no está registrada.'], 401);
        }

        // La credencial debe pertenecer al usuario cuyo nombre se escribió
        if ($expectedUser === 0 || (int)$row['id'] !== $expectedUser) {
            log_attempt((int)$row['id'], 'fingerprint', false, 'Credencial de otro usuario');
            json_out(['success' => false, 'message' => 'Autenticación fallida.'], 401);
        }

        // Si el autenticador envía userHandle, debe coincidir con el dueño
        if (!empty($cred['response']['userHandle'])) {
            if (b64u_decode($cred['response']['userHandle']) !== (string)$row['id']) {
                log_attempt((int)$row['id'], 'fingerprint', false, 'userHandle no coincide');
                json_out(['success' => false, 'message' => 'Autenticación fallida.'], 401);
            }
        }

        // Lanza excepción si la firma, el origin, el rpId o el contador no son válidos
        $webAuthn->processGet(
            b64u_decode((string)($cred['response']['clientDataJSON'] ?? '')),
            b64u_decode((string)($cred['response']['authenticatorData'] ?? '')),
            b64u_decode((string)($cred['response']['signature'] ?? '')),
            $row['public_key'],
            $challenge,
            (int)$row['sign_count'],
            requireUserVerification: true,
            requireUserPresent: true
        );

        $newCount = $webAuthn->getSignatureCounter();

        $update = $pdo->prepare('
            UPDATE webauthn_credentials
            SET sign_count = ?, last_used_at = NOW()
            WHERE id = ?
        ');
        $update->execute([(int)($newCount ?? $row['sign_count']), (int)$row['cred_row_id']]);

        log_attempt((int)$row['id'], 'fingerprint', true, null);

        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role']
        ];

        json_out(['success' => true, 'redirect' => 'dashboard.php']);
    }

    json_out(['success' => false, 'message' => 'Acción inválida.'], 400);

} catch (Throwable $e) {

    error_log('WebAuthn verify (' . $action . '): ' . $e->getMessage());

    if ($action === 'login') {
        try {
            log_attempt(null, 'fingerprint', false, 'Verificación WebAuthn fallida');
        } catch (Throwable $ignored) {}
    }

    json_out(['success' => false, 'message' => 'No se pudo verificar la huella.'], 400);
}