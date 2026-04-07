<?php

require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/Uuid.php';
require_once __DIR__ . '/../Utils/NifValidator.php';
require_once __DIR__ . '/../Utils/Logs.php';

class CompanyController {

    public static function get(PDO $pdo) {
        $userId = Auth::check();

        $stmt = $pdo->prepare("
            SELECT
                id,
                razon_social,
                nif,
                direccion,
                pais,
                logo_url
            FROM companies
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        $company = $stmt->fetch();

        echo json_encode($company ?: []);
    }

    public static function create(PDO $pdo) {
        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        if (
            empty(trim($data['razon_social'] ?? '')) ||
            empty(trim($data['nif'] ?? ''))
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos incompletos']);
            return;
        }
        if (!NifValidator::validar($data['nif'])) {
            http_response_code(400);
            echo json_encode(['error' => 'NIF de empresa no válido']);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO companies
            (id, user_id, razon_social, nif, direccion, pais)
            VALUES
            (:id, :user_id, :razon_social, :nif, :direccion, :pais)
        ");

        try {
            $stmt->execute([
                'id' => uuidv4(),
                'user_id' => $userId,
                'razon_social' => $data['razon_social'],
                'nif' => $data['nif'],
                'direccion' => $data['direccion'] ?? null,
                'pais' => $data['pais'] ?? 'ES'
            ]);

            crearLog(
                $pdo,
                $userId,
                'EMPRESA_CREADA',
                'Empresa creada: ' . $data['razon_social'] . ' (' . $data['nif'] . ')'
            );

        } catch (PDOException $e) {
            http_response_code(409);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_EMPRESA_CREAR',
                $e->getMessage()
            );
            echo json_encode(['error' => 'La empresa ya existe']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    public static function update(PDO $pdo) {
        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

         try {

            if (
                empty(trim($data['razon_social'] ?? '')) ||
                empty(trim($data['nif'] ?? ''))
            ){
                http_response_code(400);
                throw new Exception('Datos incompletos');
                return;
            }

            if (!NifValidator::validar($data['nif'])) {
                http_response_code(400);
                throw new Exception('NIF de empresa no válido');
                return;
            }

            if (!empty($data['direccion'])) {
                if (preg_match('/\b\d{5}\b/', $data['direccion']) !== 1) {
                    http_response_code(400);
                    throw new Exception('Código postal no válido');
                    return;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE companies SET
                    razon_social = :razon_social,
                    nif = :nif,
                    direccion = :direccion,
                    pais = :pais
                WHERE user_id = :user_id
            ");

            $stmt->execute([
                'razon_social' => $data['razon_social'],
                'nif' => $data['nif'],
                'direccion' => $data['direccion'] ?? null,
                'pais' => $data['pais'] ?? 'ES',
                'user_id' => $userId
            ]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                throw new Exception('Empresa no encontrada o valores idénticos a los originales');
                return;
            }

            crearLog(
                $pdo,
                $userId,
                'EMPRESA_MODIFICADA',
                'Empresa actualizada: ' . $data['razon_social'] . ' (' . $data['nif'] . ')'
            );

            echo json_encode(['ok' => true]);
        
        }catch (Throwable $e) {
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_EMPRESA_UPDATE',
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

    public static function uploadLogo(PDO $pdo) {

        $userId = Auth::check();

        if (!isset($_FILES['logo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file']);
            return;
        }

        $file = $_FILES['logo'];

        // 🔒 Validaciones
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir archivo");
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception("El logo no puede superar 2MB");
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
            throw new Exception("Formato no permitido (solo PNG/JPG)");
        }

        // 📁 generar nombre único
        $filename = 'logos/' . uniqid() . '.' . $ext;

        // 🚀 usar TU clase R2Storage (IMPORTANTE)
        $url = R2Storage::upload($file['tmp_name'], $filename);

        // 💾 guardar en BD
        $stmt = $pdo->prepare("
            UPDATE companies
            SET logo_url = :logo
            WHERE user_id = :user
        ");

        $stmt->execute([
            'logo' => $url,
            'user' => $userId
        ]);

        crearLog(
                $pdo,
                $userId,
                'EMPRESA_MODIFICADA',
                'Logo actualizado: ' . $file['name'] . ')'
            );

        echo json_encode([
            'logo_url' => $url
        ]);
    }
}