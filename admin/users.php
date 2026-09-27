<?php

require_once __DIR__ . '/../../config/config.php';
$pdo = db();

require_role('Administrador');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');

    $name =
        trim($_POST['name'] ?? '');

    $email =
        trim($_POST['email'] ?? '');

    $password =
        $_POST['password'] ?? '';

    $role =
        $_POST['role'] ?? 'Gestor';

    $pattern =
        trim($_POST['pattern'] ?? '');

    if (
        $name === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $password === '' ||
        $pattern === ''
    ) {

        $message =
            'Todos los campos son obligatorios.';

    } elseif (
        !in_array(
            $role,
            ['Administrador', 'Gestor'],
            true
        )
    ) {

        $message =
            'Rol inválido.';

    } else {

        $passwordHash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $patternHash =
            password_hash(
                $pattern,
                PASSWORD_DEFAULT
            );

        try {

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    name,
                    email,
                    password_hash,
                    role,
                    active,
                    access_pattern_hash
                )
                VALUES (?, ?, ?, ?, 1, ?)
            ");

            $stmt->execute([
                $name,
                $email,
                $passwordHash,
                $role,
                $patternHash
            ]);

            $message =
                'Usuario registrado correctamente.';

        } catch (PDOException $e) {

            $message =
                'No se pudo registrar el usuario.';
        }
    }
}

$stmt = $pdo->query("
    SELECT
        id,
        name,
        email,
        role,
        active,
        created_at
    FROM users
    ORDER BY id DESC
");

$users = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<title>Administrar usuarios</title>

<link
    rel="stylesheet"
    href="../assets/style.css"
>

</head>

<body>

<div class="container">

<div class="card">

<h1>Administrar usuarios</h1>

<?php if ($message): ?>

<div class="message">
    <?= e($message) ?>
</div>

<?php endif; ?>

<h2>Registrar usuario</h2>

<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e(csrf_token()) ?>"
>

<label>Nombre</label>

<input
    type="text"
    name="name"
    required
>

<label>Correo</label>

<input
    type="email"
    name="email"
    required
>

<label>Contraseña</label>

<input
    type="password"
    name="password"
    required
>

<label>Rol</label>

<select name="role">

<option value="Gestor">
    Gestor
</option>

<option value="Administrador">
    Administrador
</option>

</select>

<label>Patrón</label>

<input
    type="hidden"
    name="pattern"
    id="adminPattern"
>

<div
    class="pattern-grid"
    id="adminPatternGrid"
></div>

<br>

<button type="submit">
    Registrar usuario
</button>

</form>

<hr>

<h2>Usuarios registrados</h2>

<table>

<thead>

<tr>

<th>ID</th>

<th>Nombre</th>

<th>Correo</th>

<th>Rol</th>

<th>Estado</th>

</tr>

</thead>

<tbody>

<?php foreach ($users as $user): ?>

<tr>

<td>
    <?= e((string)$user['id']) ?>
</td>

<td>
    <?= e($user['name']) ?>
</td>

<td>
    <?= e($user['email']) ?>
</td>

<td>
    <?= e($user['role']) ?>
</td>

<td>

<?= $user['active']
    ? 'Activo'
    : 'Inactivo'
?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<br>

<a href="../dashboard.php">
    ← Regresar al panel
</a>

</div>

</div>

<script>

const grid =
    document.getElementById(
        'adminPatternGrid'
    );

const input =
    document.getElementById(
        'adminPattern'
    );

let pattern = [];

for (let i = 1; i <= 9; i++) {

    const point =
        document.createElement('div');

    point.className =
        'pattern-point';

    point.textContent = i;

    point.addEventListener(
        'click',
        () => {

            if (pattern.includes(i)) {
                return;
            }

            pattern.push(i);

            point.classList.add(
                'selected'
            );

            input.value =
                pattern.join('');
        }
    );

    grid.appendChild(point);
}

</script>

</body>

</html>