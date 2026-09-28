<?php

require_once __DIR__ . '/../config/config.php';

require_login();

$user = current_user();

$roleLabel = $user['role'] ?? 'Gestor';
$accessMethods = [
    'face' => 'Reconocimiento facial',
    'voice' => 'Reconocimiento de voz',
    'fingerprint' => 'Huella digital',
    'pattern' => 'Patrón de acceso',
];
$pdo = db();

$accessMethod = $user['access_method'] ?? '';
if (!isset($accessMethods[$accessMethod])) {
    $stmt = $pdo->prepare(
        "SELECT method
         FROM login_attempts
         WHERE user_id = ?
           AND success = 1
           AND method IN ('face', 'voice', 'fingerprint', 'pattern')
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$user['id']]);
    $accessMethod = (string)($stmt->fetchColumn() ?: '');
}

$accessMethodLabel = $accessMethods[$accessMethod] ?? 'No especificado';

$stmt = $pdo->prepare('SELECT face_template, voice_template FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$bio = $stmt->fetch() ?: [];

$stmt = $pdo->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?');
$stmt->execute([(int)$user['id']]);

$biometrics = [
    'face'        => ['Rostro', !empty($bio['face_template'])],
    'voice'       => ['Voz', !empty($bio['voice_template'])],
    'fingerprint' => ['Huella', (int)$stmt->fetchColumn() > 0],
];

?>
<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<title>Panel</title>

<link
    rel="stylesheet"
    href="assets/style.css"
>

</head>

<body>

<div class="container">

<div class="card welcome-card">

<div class="welcome-header">

<div class="welcome-avatar">
    <?= e(strtoupper(substr((string)($user['name'] ?? 'U'), 0, 1))) ?>
</div>

<div class="welcome-copy">

<span class="welcome-tag">Panel principal</span>

<h1>
    Bienvenido
</h1>

</div>

</div>

<div class="welcome-details">

<div class="detail-row">
    <span class="detail-label">Nombre de usuario:</span>
    <strong><?= e($user['name']) ?></strong>
</div>

<div class="detail-row">
    <span class="detail-label">Rol:</span>
    <strong><?= e($roleLabel) ?></strong>
</div>

<div class="detail-row">
    <span class="detail-label">Método de acceso:</span>
    <strong><?= e($accessMethodLabel) ?></strong>
</div>

</div>

<?php if (!empty($_GET['ok'])): ?>

<div class="alert">
    <?= e((string)$_GET['ok']) ?>
</div>

<?php endif; ?>

<h2>Datos biométricos</h2>

<?php foreach ($biometrics as $type => [$label, $registered]): ?>

<div class="detail-row">

    <span class="detail-label"><?= e($label) ?>:</span>

    <strong><?= $registered ? 'Registrado' : 'No registrado' ?></strong>

    <?php if ($registered): ?>

    <form
        method="POST"
        action="biometric_delete.php"
        style="display:inline"
        onsubmit="return confirm('¿Borrar tu <?= e(strtolower($label)) ?> registrado?');"
    >
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <button type="submit">Borrar</button>
    </form>

    <?php endif; ?>

</div>

<?php endforeach; ?>

<div class="action-bar">

<?php if ($user['role'] === 'Administrador'): ?>

<a
    class="button"
    href="admin/users.php"
>
    Administrar usuarios
</a>

<?php endif; ?>

<a
    class="button"
    href="biometric.php?method=fingerprint&setup=1"
>
    Registrar huella de este dispositivo
</a>

<a
    class="button"
    href="biometric.php?method=face&setup=1"
>
    Registrar rostro
</a>

<a
    class="button"
    href="biometric.php?method=voice&setup=1"
>
    Registrar voz
</a>

<a
    class="button button-secondary"
    href="logout.php"
>
    Cerrar sesión
</a>

</div>

</div>

</div>

</body>

</html>