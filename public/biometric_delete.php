<?php

require_once __DIR__ . '/../config/config.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verify_csrf((string)($_POST['csrf'] ?? ''));

$pdo  = db();
$user = current_user();
$id   = (int)$user['id'];
$type = $_POST['type'] ?? '';

switch ($type) {

    case 'face':
        $pdo->prepare('UPDATE users SET face_template = NULL WHERE id = ?')->execute([$id]);
        $message = 'Rostro eliminado. Ya puedes registrarlo de nuevo.';
        break;

    case 'voice':
        $pdo->prepare('UPDATE users SET voice_template = NULL WHERE id = ?')->execute([$id]);
        $message = 'Voz eliminada. Ya puedes registrarla de nuevo.';
        break;

    case 'fingerprint':
        $pdo->prepare('DELETE FROM webauthn_credentials WHERE user_id = ?')->execute([$id]);
        $message = 'Huella eliminada. Ya puedes registrarla de nuevo.';
        break;

    case 'all':
        $pdo->prepare('UPDATE users SET face_template = NULL, voice_template = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM webauthn_credentials WHERE user_id = ?')->execute([$id]);
        $message = 'Todos los datos biométricos fueron eliminados.';
        break;

    default:
        header('Location: dashboard.php');
        exit;
}

log_attempt($id, $type . '-delete', true, null);

header('Location: dashboard.php?ok=' . urlencode($message));
exit;
