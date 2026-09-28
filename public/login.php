<?php

require_once __DIR__ . '/../config/config.php';
$pdo = db();

 $setupPattern = (
    ($_GET['setup'] ?? $_POST['setup'] ?? '') === 'pattern' &&
    !empty($_SESSION['user']['id'])
);

if (isset($_SESSION['user']) && !$setupPattern) {
    header('Location: dashboard.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');

    $name = trim($_POST['email'] ?? '');
    $pattern = trim($_POST['pattern'] ?? '');

    if ($name === '' || $pattern === '') {

        $message = 'Completa todos los campos.';

    } else {

        $identifier = trim($name);
        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE name = ?
            LIMIT 1
        ");

        $stmt->execute([$identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            $safeName = trim($identifier);

            if ($safeName === '' || strlen($safeName) > 120) {
                $message = 'Nombre inválido o demasiado largo.';
            } else {
                $emailPrefix = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '.', $safeName));
                $emailPrefix = trim(substr($emailPrefix, 0, 80), '.');
                $email = ($emailPrefix !== '' ? $emailPrefix : 'usuario') . '.' . bin2hex(random_bytes(6)) . '@local.test';
                $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $patternHash = password_hash($pattern, PASSWORD_DEFAULT);

                $insert = $pdo->prepare("
                    INSERT INTO users
                    (name, email, password_hash, role, active, access_pattern_hash)
                    VALUES (?, ?, ?, 'Gestor', 1, ?)
                ");

                $insert->execute([$safeName, $email, $passwordHash, $patternHash]);

                $userId = (int)$pdo->lastInsertId();
                $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch();
            }
        }

        if (
            $user &&
            (int)$user['active'] === 1 &&
            empty($user['access_pattern_hash'])
        ) {
            $patternHash = password_hash($pattern, PASSWORD_DEFAULT);
            $update = $pdo->prepare(
                'UPDATE users SET access_pattern_hash = ? WHERE id = ?'
            );
            $update->execute([$patternHash, (int)$user['id']]);
            $user['access_pattern_hash'] = $patternHash;
        }

        $valid = false;

        if (
            $user &&
            (int)$user['active'] === 1 &&
            !empty($user['access_pattern_hash'])
        ) {

            $valid = password_verify(
                $pattern,
                $user['access_pattern_hash']
            );
        }

        log_attempt(
            $pdo,
            $user['id'] ?? null,
            'pattern',
            $valid,
            $valid ? 'Autenticación correcta' : 'Patrón incorrecto'
        );

        if ($valid) {

            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'access_method' => 'pattern'
            ];

            header('Location: dashboard.php');
            exit;
        }

        $message = 'Usuario no autorizado.';
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Patrón de acceso</title>

<link
    rel="stylesheet"
    href="assets/style.css"
>

</head>

<body>

<div class="container">

<div class="card auth-card">

<div class="auth-header">
    <span class="auth-badge">🔐</span>
    <h1>Patrón de acceso</h1>
    <p>Escribe tu nombre y dibuja tu secuencia segura.</p>
</div>

<?php if ($message): ?>

<div class="alert danger">
    <?= e($message) ?>
</div>

<?php endif; ?>

<form method="POST" class="auth-form" id="patternForm">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e(csrf_token()) ?>"
>

<div class="field">
    <label for="email">Nombre</label>
    <input
        id="email"
        type="text"
        name="email"
        placeholder="Tu nombre o identificador"
        required
    >
</div>

<div class="field">
    <label>Selecciona tu patrón</label>
    <input
        type="hidden"
        name="pattern"
        id="pattern"
    >

    <div
        id="patternGrid"
        class="pattern-grid"
        aria-label="Cuadrícula de patrón"
    ></div>
</div>

<div class="pattern-hint">
    Dibuja al menos 4 puntos para continuar.
</div>

<button type="submit" class="primary-btn">
    Autenticar
</button>

</form>

<div class="auth-footer">
    <a href="index.php" class="back-link">
        ← Regresar
    </a>
</div>

</div>

</div>

<script>

const grid = document.getElementById('patternGrid');
const patternInput = document.getElementById('pattern');
const form = document.getElementById('patternForm');
let pattern = [];

for (let i = 1; i <= 9; i++) {

    const point = document.createElement('button');
    point.type = 'button';
    point.className = 'pattern-point';
    point.textContent = i;
    point.dataset.number = i;

    point.addEventListener('click', () => {

        if (pattern.includes(i)) {
            return;
        }

        pattern.push(i);
        point.classList.add('selected');
        patternInput.value = pattern.join('');
    });

    grid.appendChild(point);
}

form.addEventListener('submit', (event) => {

    if (pattern.length < 4) {
        event.preventDefault();
        const message = document.createElement('div');
        message.className = 'alert danger';
        message.textContent = 'Debes seleccionar al menos 4 puntos.';

        const existing = form.querySelector('.alert.danger');
        if (existing) {
            existing.remove();
        }

        form.insertBefore(message, form.firstChild);
        return;
    }

    patternInput.value = pattern.join('');
});

</script>

</body>
</html>