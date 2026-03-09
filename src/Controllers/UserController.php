<?php
/** @var TwoFactor $twoFactor */
require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/TwoFactor.php';
require_once __DIR__ . '/../Utils/Logs.php';
require_once __DIR__ . '/../Utils/NifValidator.php';

class UserController
{
    public static function me(PDO $pdo) {
        $userId = Auth::check();

        try{
            // Usuario
            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(404);
                throw new Exception('Usuario no encontrado');
                return;
            }

            // Empresa (si existe)
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    razon_social,
                    nif,
                    direccion,
                    pais
                FROM companies
                WHERE user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            

            echo json_encode([
                'user' => $user,
                'company' => $company ?: null
            ]);
        }catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_USER_ME',
                $e->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }
    }

    public static function enable2FA(PDO $pdo) {
        $userId = Auth::check();
        
        try{
            $secret = TwoFactor::generateSecret();

            // Guardar en DB
            $stmt = $pdo->prepare("UPDATE users SET two_fa_secret = :secret WHERE id = :id");
            $stmt->execute(['secret' => $secret, 'id' => $userId]);

            crearLog(
                $pdo,
                $userId,
                '2FA_INICIADO',
                'Usuario inició configuración 2FA'
            );

            // Parámetros limpios para el QR
            $issuer = "InAltera";
            $userLabel = "Usuario"; 
            
            // Construimos el string otpauth
            $otpauth = "otpauth://totp/" . $issuer . ":" . $userLabel . "?secret=" . $secret . "&issuer=" . $issuer;
            
            // Generamos la URL de Google Charts usando urlencode solo en el contenido del QR
            $qrChart = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauth);

            echo json_encode([
                'qr' => $qrChart,
                'secret' => $secret
            ]);
        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_ENABLE_2FA',
                $e->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }
    }

    public static function verify2FA(PDO $pdo) {
        $userId = Auth::check();
        $data = json_decode(file_get_contents("php://input"), true);
        try{
            $code = $data['code'] ?? '';

            $stmt = $pdo->prepare("SELECT two_fa_secret FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                throw new Exception("Usuario no encontrado");
            }

            if (TwoFactor::verifyCode($user['two_fa_secret'], $code)) {
                // 🏁 SI ES CORRECTO: Activamos definitivamente
                $stmt = $pdo->prepare("UPDATE users SET two_fa_enabled = 1 WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                crearLog(
                    $pdo,
                    $userId,
                    '2FA_ACTIVADO',
                    'Usuario activó autenticación en dos factores'
                );
                echo json_encode(['ok' => true]);
            } else {
                http_response_code(400);
                throw new Exception('Código inválido');
            }
        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_VERIFY_2FA',
                $e->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }
    }

    public static function disable2FA(PDO $pdo) {
        $userId = Auth::check();

        try {

            $stmt = $pdo->prepare("
                UPDATE users
                SET two_fa_enabled = 0,
                two_fa_secret = NULL
                WHERE id = :id
            ");

            $stmt->execute(['id' => $userId]);

            // 🔹 Log evento de seguridad
            crearLog(
                $pdo,
                $userId,
                '2FA_DESACTIVADO',
                'Usuario desactivó autenticación en dos factores'
            );

            echo json_encode(['ok' => true]);

        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_DISABLE_2FA',
                $e->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'error' => 'Error desactivando 2FA'
            ]);
        }
    }

    public static function UpdateDatos(PDO $pdo){
        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        try{
            if (empty($data['nombre'])) {
                http_response_code(400);
                throw new Exception('nombre requerido');
            }

            if (empty($data['apellidos'])) {
                http_response_code(400);
                throw new Exception('apellidos requeridos');
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono
                WHERE id = :id
            ");

            $stmt->execute([
                'nombre' => $data['nombre'],
                'apellidos' => $data['apellidos'],
                'telefono' => $data['telefono'],
                'id' => $userId
            ]);

            crearLog(
                $pdo,
                $userId,
                'USER_DATOS_MODIFICADOS',
                'Usuario actualizó datos personales'
            );

            echo json_encode(['ok' => true]);
        }catch (PDOException $e) {
            crearLog(
            $pdo,
            $userId ?? null,
            'ERROR_USER_UPDATE',
            $e->getMessage()
        );

            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }
    }

    public static function updateEmail(PDO $pdo){
        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        try {
            if (empty($data['email'])) {
            http_response_code(400);
            throw new Exception('email requerido');

            return;
        }

            $stmt = $pdo->prepare("
                UPDATE users
                SET email = :email
                WHERE id = :id
            ");

            $stmt->execute([
                'email' => $data['email'],
                'id' => $userId
            ]);

            crearLog(
                $pdo,
                $userId,
                'EMAIL_MODIFICADO',
                'Usuario cambió su email'
            );

            echo json_encode(['ok' => true]);

        } catch (PDOException $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_EMAIL_UPDATE',
                $e->getMessage()
            );
            // Código SQLSTATE para duplicados (UNIQUE)
            if ($e->getCode() == 23000) {
                http_response_code(400);
                echo json_encode(['error' => 'El email ya está en uso']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Error del servidor']);
            }
        }
    }

    public static function updatePassword(PDO $pdo){
        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        try {

            if (empty($data['current_password']) || empty($data['new_password'])) {
                http_response_code(400);
                throw new Exception('Datos incompletos');
            }

            // Obtener contraseña actual
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
                http_response_code(400);
                throw new Exception('Contraseña actual incorrecta');
            }

            if (password_verify($data['new_password'], $user['password_hash'])) {
                http_response_code(400);
                throw new Exception('La nueva contraseña no puede ser igual a la actual');
            }

            $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :password
                WHERE id = :id
            ");

            $stmt->execute([
                'password' => $newHash,
                'id' => $userId
            ]);
            
            crearLog(
                $pdo,
                $userId,
                'PASSWORD_CAMBIADO',
                'Usuario cambió su contraseña'
            );

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {

        // 🔹 Log de error
        crearLog(
            $pdo,
            $userId ?? null,
            'ERROR_PASSWORD_UPDATE',
            $e->getMessage()
        );

        if (http_response_code() === 200) {
            http_response_code(500);
        }

        echo json_encode([
            'error' => $e->getMessage()
        ]);
    }
    }
}
