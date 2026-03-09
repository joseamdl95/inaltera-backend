<?php
require_once __DIR__ . '/../Utils/Logs.php';
require_once __DIR__ . '/../Utils/NifValidator.php';

class SifController {

    // 🔹 LISTAR SIF DEL USUARIO
    public static function index(PDO $pdo) {

        $userId = Auth::check();

        $stmt = $pdo->prepare("
            SELECT id, alias, nif, software_nombre, version, es_default
            FROM sif_configs
            WHERE user_id = :user_id
            ORDER BY es_default DESC, created_at ASC
        ");

        $stmt->execute(['user_id' => $userId]);

        $sifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'data' => $sifs
        ]);
    }

    // 🔹 CREAR NUEVO SIF
    public static function store(PDO $pdo) {

        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        try {

            if (
                empty($data['alias']) ||
                empty($data['software_nombre']) ||
                empty($data['version'])
            ) {
                http_response_code(400);
                throw new Exception('Campos obligatorios incompletos');
            }

            if ($data['nif'] && !NifValidator::validar($data['nif'])) {
                http_response_code(400);
                echo json_encode(['error' => 'NIF no válido']);
                return;
            }

            $id = uuidv4();

            $stmt = $pdo->prepare("
                INSERT INTO sif_configs
                (id, user_id, alias, nif, software_nombre, version, es_default)
                VALUES
                (:id, :user_id, :alias, :nif, :software, :version, 0)
            ");

            $stmt->execute([
                'id' => $id,
                'user_id' => $userId,
                'alias' => $data['alias'],
                'nif' => $data['nif'] ?? null,
                'software' => $data['software_nombre'],
                'version' => $data['version']
            ]);

            crearLog(
                $pdo,
                $userId,
                'SIF_CREADO',
                'SIF creado: ' . $data['alias'] . ' (' . $data['software_nombre'] . ' ' . $data['version'] . ')'
            );

            echo json_encode([
                'ok' => true,
                'id' => $id
            ]);

        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_SIF_STORE',
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

    public static function createDefaultSif(PDO $pdo, $userId) {
        $stmt = $pdo->prepare("
            INSERT INTO sif_configs
            (id, user_id, alias, nif, software_nombre, version, es_default)
            VALUES
            (:id, :user_id, 'Sistema','B12345678', 'InAltera', '1.0', 1)
        ");

        $stmt->execute([
            'id' => uuidv4(),
            'user_id' => $userId
        ]);
    }

    public static function update(PDO $pdo, $id) {

        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        try {

            $stmt = $pdo->prepare("
                SELECT software_nombre FROM sif_configs 
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                'id' => $id,
                'user_id' => $userId
            ]);

            $sif = $stmt->fetch();

            if (!$sif) {
                http_response_code(404);
                throw new Exception('SIF no encontrado');
            }

            if ($sif['software_nombre'] === 'InAltera') {
                http_response_code(403);
                throw new Exception('No puedes modificar el SIF del sistema');
            }

            if ($data['nif'] && !NifValidator::validar($data['nif'])) {
                http_response_code(400);
                echo json_encode(['error' => 'NIF no válido']);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE sif_configs
                SET alias = :alias, version = :version, nif = :nif
                WHERE id = :id AND user_id = :user_id
            ");

            $stmt->execute([
                'alias' => $data['alias'],
                'version' => $data['version'],
                'nif' => $data['nif'] ?? null,
                'id' => $id,
                'user_id' => $userId
            ]);

            crearLog(
                $pdo,
                $userId,
                'SIF_MODIFICADO',
                'SIF actualizado: ' . $data['alias'] . ' versión ' . $data['version']
            );

            echo json_encode(['ok' => true]);

        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_SIF_UPDATE',
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

    public static function setDefault(PDO $pdo, $id) {

        $userId = Auth::check();

        try {

            $stmt = $pdo->prepare("
                UPDATE sif_configs 
                SET es_default = 0 
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);

            $stmt = $pdo->prepare("
                UPDATE sif_configs 
                SET es_default = 1 
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                'id' => $id,
                'user_id' => $userId
            ]);

            crearLog(
                $pdo,
                $userId,
                'SIF_DEFAULT_CAMBIADO',
                'Nuevo SIF por defecto ID: ' . $id
            );

            echo json_encode(['ok' => true]);

        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_SIF_DEFAULT',
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