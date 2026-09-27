<?php

require_once __DIR__ . '/../config/config.php';

require_login();

$user = current_user();

$displayUserId = user_public_id($user);

$roleLabel = $user['role'] ?? 'Gestor';

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
    Bienvenido,
    <?= e($user['name']) ?>
</h1>

</div>

</div>

<div class="welcome-details">

<div class="detail-row">
    <span class="detail-label">Nombre:</span>
    <strong><?= e($user['name']) ?></strong>
</div>

<div class="detail-row">
    <span class="detail-label">ID:</span>
    <strong><?= e($displayUserId) ?></strong>
</div>

<div class="detail-row">
    <span class="detail-label">Rol:</span>
    <strong><?= e($roleLabel) ?></strong>
</div>

</div>

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