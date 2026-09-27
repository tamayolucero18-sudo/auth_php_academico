# Sistema de autenticación multimodal en PHP

Proyecto académico para demostrar:
- Selección de método de autenticación.
- Reconocimiento facial, voz y huella mediante **credenciales/plantillas de prueba autorizadas**.
- Patrón de acceso visual personalizado por cada usuario y protegido con `password_hash`.
- Roles `Administrator` y `Gestor`.
- Alta, edición, activación/desactivación y consulta de usuarios.
- Sesiones seguras, CSRF, validación, consultas preparadas y registro de intentos.

## Requisitos

- XAMPP (Apache + PHP + MySQL/MariaDB).
- PHP 8.1+ recomendado.
- Extensión PDO MySQL habilitada.

## Instalación

1. Copia la carpeta `auth_php_academico` dentro de `C:\xampp\htdocs\`.
2. Inicia Apache y MySQL desde XAMPP.
3. Abre:
   `http://localhost/auth_php_academico/setup.php`
4. Después elimina o renombra `setup.php`.
5. Entra a:
   `http://localhost/auth_php_academico/public/`

Usuario de demostración:
- Correo: `admin@demo.local`
- Patrón de demostración: dibuja los puntos `1 → 2 → 3 → 6` (equivale a `1236`).
- Contraseña de respaldo: `Admin123!`
- Face demo: `DEMO-FACE-ADMIN`
- Voice demo: `DEMO-VOICE-ADMIN`
- Fingerprint demo: `DEMO-FINGERPRINT-ADMIN`

## Flujo de demostración

Seleccionar método → introducir identificador y credencial de prueba → validar usuario → bienvenida y rol → si es Administrator, abrir administración de usuarios.

## Importante sobre biometría

Este proyecto **no captura ni almacena datos biométricos reales**. Las plantillas `DEMO-*` representan datos de prueba autorizados. En un sistema real, el reconocimiento facial/voz requiere un motor de verificación biométrica y la huella en navegador se implementa normalmente mediante un autenticador del dispositivo con WebAuthn/FIDO2, evitando que la aplicación reciba la huella cruda.

Para una entrega académica, conviene explicar esta diferencia en la presentación y mostrar el flujo con las plantillas de prueba.

## Seguridad incluida

- `password_hash()` / `password_verify()`.
- PDO con consultas preparadas.
- Validación de correo, rol y entradas.
- Token CSRF.
- `session_regenerate_id(true)` al autenticar.
- Cookies `HttpOnly`, `SameSite=Lax` y `Secure` cuando se usa HTTPS.
- Control de acceso por rol en servidor.
- Registro de intentos de autenticación con IP, método, resultado y fecha.
- No guardar fotos, audio o huellas reales.

## Diagrama lógico

```text
USERS
 ├── id (PK)
 ├── name
 ├── email (UNIQUE)
 ├── password_hash
 ├── role
 ├── active
 ├── face_template
 ├── voice_template
 ├── fingerprint_template
 └── access_pattern_hash
        │
        │ 1:N
        ▼
LOGIN_ATTEMPTS
 ├── id (PK)
 ├── user_id (FK -> users.id)
 ├── method
 ├── success
 ├── ip_address
 ├── reason
 └── created_at
```

## Investigación breve de herramientas

### Reconocimiento facial
Para un sistema real puede utilizarse un modelo de reconocimiento facial que genere un embedding y compare una plantilla registrada. El navegador puede capturar la cámara con `getUserMedia`, pero el tratamiento debe diseñarse para no exponer innecesariamente imágenes biométricas.

### Reconocimiento de voz
El reconocimiento de voz y la verificación de identidad por voz son problemas distintos. Para autenticar a una persona se requiere speaker verification, no solamente convertir voz a texto. En producción se debe utilizar un proveedor o modelo especializado y consentimiento explícito.

### Huella digital
La opción recomendada para una aplicación web es WebAuthn/FIDO2 con un autenticador de plataforma. La aplicación recibe una prueba criptográfica de posesión; no recibe la huella biométrica cruda.

### Patrón de acceso
El Administrator registra visualmente un patrón de 4 a 9 puntos para cada usuario mediante una cuadrícula 3×3. El navegador convierte el recorrido en una secuencia como `1236` y PHP almacena únicamente `password_hash(secuencia, PASSWORD_DEFAULT)`. Durante el acceso, el usuario vuelve a dibujar el patrón y se verifica con `password_verify()`. El patrón nunca se guarda en texto plano.

## Video de 5–10 minutos

Guion sugerido:
1. 0:00–1:00 Presentación del problema y arquitectura.
2. 1:00–2:00 Explicar BD y medidas de seguridad.
3. 2:00–4:00 Probar patrón de acceso y mostrar bienvenida.
4. 4:00–5:30 Probar métodos biométricos con credenciales de prueba autorizadas.
5. 5:30–7:30 Entrar como Administrator y crear/editar/desactivar usuarios.
6. 7:30–9:00 Mostrar tabla `login_attempts` y explicar protección.
7. 9:00–10:00 Explicar limitaciones y cómo se integraría WebAuthn/proveedor biométrico real.


## Patrón visual

Cada usuario puede tener su propio patrón. Para crear un usuario:
1. El Administrator abre **Administrar usuarios**.
2. Escribe nombre, correo, contraseña y rol.
3. Dibuja de 4 a 9 puntos en la cuadrícula.
4. Guarda el usuario.
5. Para iniciar sesión, el usuario selecciona **Patrón de acceso**, introduce su correo y vuelve a dibujar exactamente el mismo recorrido.

Al editar un usuario, el patrón existente no se puede recuperar (porque está hasheado). Para cambiarlo, el Administrator dibuja un nuevo patrón y guarda los cambios.
