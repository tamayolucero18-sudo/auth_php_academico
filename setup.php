<?php

require_once __DIR__ . '/config/config.php';

try {
    $setupPdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $setupPdo->exec("
        CREATE DATABASE IF NOT EXISTS auth_academico
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('Administrador','Gestor')
                NOT NULL DEFAULT 'Gestor',
            active TINYINT(1) NOT NULL DEFAULT 1,
            face_template TEXT NULL,
            voice_template TEXT NULL,
            fingerprint_template TEXT NULL,
            access_pattern_hash VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            method VARCHAR(50) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NULL,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            credential_id VARCHAR(255) NOT NULL UNIQUE,
            public_key TEXT NOT NULL,
            sign_count INT UNSIGNED NOT NULL DEFAULT 0,
            label VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            CONSTRAINT fk_webauthn_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        )
    ");

    $email = 'admin@demo.local';

    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE email = ?"
    );

    $stmt->execute([$email]);

    if (!$stmt->fetch()) {

        $passwordHash = password_hash(
            'Admin123!',
            PASSWORD_DEFAULT
        );

        $patternHash = password_hash(
            '1236',
            PASSWORD_DEFAULT
        );

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
            'Administrador',
            $email,
            $passwordHash,
            'Administrador',
            $patternHash
        ]);
    }

    echo "<h2>Configuración completada</h2>";
    echo "<p>Administrador creado:</p>";
    echo "<p><strong>Correo:</strong> admin@demo.local</p>";
    echo "<p><strong>Contraseña:</strong> Admin123!</p>";
    echo "<p><strong>Patrón:</strong> 1 → 2 → 3 → 6</p>";

} catch (Throwable $e) {

    http_response_code(500);

    echo "Error: " . e($e->getMessage());
}