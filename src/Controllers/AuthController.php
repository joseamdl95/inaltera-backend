<?php
/** @var TwoFactor $twoFactor */
require_once __DIR__ . '/../Utils/Uuid.php';
require_once __DIR__ . '/../Utils/JWT.php';
require_once __DIR__ . '/../Utils/TwoFactor.php';
require_once __DIR__ . '/SifController.php';
require_once __DIR__ . '/../Utils/Logs.php';

use Firebase\JWT\JWT;

class AuthController {

    public static function register(PDO $pdo) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['email'], $data['password'], $data['nombre'], $data['apellidos'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }

        $id = uuidv4();
        $email = $data['email'];
        $nombre = $data['nombre'];
        $apellidos = $data['apellidos'];
        $telefono = $data['telefono'] ?? null;

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, nombre, apellidos, telefono, password_hash)
            VALUES (:id, :email, :nombre, :apellidos, :telefono, :password)
        ");

        try {

            $pdo->beginTransaction();

            // 1. Crear usuario
            $stmt->execute([
                'id' => $id,
                'email' => $email,
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'telefono' => $telefono,
                'password' => $passwordHash
            ]);

            // 2. Crear SIF por defecto
            SifController::createDefaultSif($pdo, $id);

            crearLog(
                $pdo,
                $id,
                'USUARIO_REGISTRADO',
                'Nuevo usuario registrado con email: ' . $email
            );

            $pdo->commit();

        } catch (PDOException $e) {

            $pdo->rollBack();

            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'El email ya está en uso']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Error del servidor']);
            }
            return;
        }

        echo json_encode(['ok' => true]);
    }

    public static function login(PDO $pdo) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['email'], $data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }

        // 🟢 CIRUGÍA: Ahora pedimos también two_fa_enabled para saber si interrumpir el login
        $stmt = $pdo->prepare("
            SELECT id, password_hash, two_fa_enabled
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciales incorrectas']);
            crearLog(
                $pdo,
                null,
                'LOGIN_FAIL',
                'Intento de login fallido para email: ' . $data['email']
            );
            return;
        }

        //Si el 2FA está activo, NO damos el token final
        if ($user['two_fa_enabled']) {
            http_response_code(200);
            echo json_encode([
                'requires_2fa' => true,
                'email' => $data['email'] // Lo pasamos para que el front sepa a quién validar
            ]);
            return;
        }

        // Si no hay 2FA, generamos el token normal
        $payload = [
            'sub' => $user['id'],
            'iat' => time(),
            'exp' => time() + 3600
        ];

        $jwtConfig = require __DIR__ . '/../../config/jwt.php';
        $secret = $jwtConfig['secret'];
        $token = JWT::encode($payload, $secret, 'HS256');

        crearLog(
            $pdo,
            $user['id'],
            'LOGIN_OK',
            'Inicio de sesión correcto'
        );

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'two_fa_enabled' => (bool)$user['two_fa_enabled']
            ]
        ]);
    }

    public static function verify2FALogin(PDO $pdo) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['email'], $data['code'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }

        // Buscamos el secreto del usuario
        $stmt = $pdo->prepare("SELECT id, two_fa_secret FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Usuario no encontrado']);
            return;
        }

        // Importamos la utilidad que creaste/crearás en src/Utils/TwoFactor.php
        require_once __DIR__ . '/../Utils/TwoFactor.php';

        // Validamos el código con el secreto guardado en la DB
        if (TwoFactor::verifyCode($user['two_fa_secret'], $data['code'])) {
            // CÓDIGO CORRECTO: Ahora sí generamos el token JWT final
            $payload = [
                'sub' => $user['id'],
                'iat' => time(),
                'exp' => time() + 3600
            ];

            $jwtConfig = require __DIR__ . '/../../config/jwt.php';
            $secret = $jwtConfig['secret'];
            $token = JWT::encode($payload, $secret, 'HS256');

            crearLog(
                $pdo,
                $user['id'],
                'LOGIN_2FA_OK',
                'Login completado con 2FA'
            );

            echo json_encode([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'two_fa_enabled' => true
                ]
            ]);
        } else {
            http_response_code(401);
            crearLog(
                $pdo,
                $user['id'],
                'LOGIN_2FA_FAIL',
                'Código 2FA inválido'
            );
            echo json_encode(['error' => 'Código 2FA inválido o expirado']);
        }
    }

    public static function forgotPassword(PDO $pdo) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['email'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email requerido']);
            return;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user) {
            // no revelar si existe o no
            echo json_encode(['ok' => true]);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $pdo->prepare("
            UPDATE users
            SET reset_token = :token,
                reset_expires = :expires
            WHERE id = :id
        ");

        $stmt->execute([
            'token' => $token,
            'expires' => $expires,
            'id' => $user['id']
        ]);

        // 🔥 MOCK → simulamos email
        $resetLink = "http://localhost:5173/reset-password?token=$token";

        crearLog(
            $pdo,
            $user['id'],
            'PASSWORD_RESET_REQUEST',
            'Solicitud de recuperación de contraseña'
        );

        echo json_encode([
            'ok' => true,
            'reset_link' => $resetLink // SOLO DEV → luego quitar
        ]);
    }

    public static function resetPassword(PDO $pdo) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['token']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, reset_expires, password_hash
            FROM users
            WHERE reset_token = :token
            LIMIT 1
        ");

        $stmt->execute(['token' => $data['token']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(400);
            echo json_encode(['error' => 'Token inválido']);
            return;
        }

        if (password_verify($data['password'], $user['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'La nueva contraseña no puede ser igual a la anterior']);
            return;
        }

        if (strtotime($user['reset_expires']) < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Token expirado']);
            return;
        }

        $newHash = password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password,
                reset_token = NULL,
                reset_expires = NULL
            WHERE id = :id
        ");

        $stmt->execute([
            'password' => $newHash,
            'id' => $user['id']
        ]);

        crearLog(
            $pdo,
            $user['id'],
            'PASSWORD_RESET_SUCCESS',
            'Contraseña restablecida correctamente'
        );

        echo json_encode(['ok' => true]);
    }
}
