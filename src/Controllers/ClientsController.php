<?php

require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/NifValidator.php';
require_once __DIR__ . '/../Utils/Logs.php';

class ClientsController
{
    // 🔹 GET /clients
    public static function index(PDO $pdo){
        $userId = Auth::check();

        // 1. Obtener empresa del usuario
        $stmt = $pdo->prepare("
            SELECT id FROM companies WHERE user_id = :user_id LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuario sin empresa']);
            return;
        }

        // 2. Obtener clientes
        $stmt = $pdo->prepare("
            SELECT id, nombre, nif, direccion, pais, created_at
            FROM clients
            WHERE company_id = :company_id
            ORDER BY created_at DESC
        ");
        $stmt->execute(['company_id' => $company['id']]);

        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($clients);
    }

    // 🔹 POST /clients
    public static function store(PDO $pdo){
        $userId = Auth::check();
        $data = json_decode(file_get_contents("php://input"), true);

        // Validación básica
        if (
            empty($data['nombre']) ||
            empty($data['nif']) ||
            empty($data['direccion'])
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan campos obligatorios']);
            return;
        }

        // 1. Obtener empresa
        $stmt = $pdo->prepare("
            SELECT id FROM companies WHERE user_id = :user_id LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuario sin empresa']);
            return;
        }

        try {
            if (!NifValidator::validar($data['nif'])) {
                http_response_code(400);
                throw new Exception('NIF / NIE / CIF no válido');
            }

            // 2. Insertar cliente
            $stmt = $pdo->prepare("
                INSERT INTO clients (id, nombre, nif, direccion, pais, company_id)
                VALUES (UUID(), :nombre, :nif, :direccion, :pais, :company_id)
            ");

            $stmt->execute([
                'nombre' => $data['nombre'],
                'nif' => $data['nif'],
                'direccion' => $data['direccion'],
                'pais' => $data['pais'] ?? 'ES',
                'company_id' => $company['id']
            ]);

            crearLog(
                $pdo,
                $userId,
                'CLIENTE_CREADO',
                'Cliente creado: ' . $data['nombre'] . ' (' . $data['nif'] . ')'
            );

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            // Control de duplicados (por unique company_id + nif)
            if ($e->getCode() === '23000') {
                http_response_code(400);
                echo json_encode(['error' => 'Cliente ya existe para esta empresa']);
                return;
            }

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_CLIENTE_CREAR',
                $e->getMessage()
            );

            throw $e;
        }
    }

    // 🔹 PUT /clients/{id}
    public static function update(PDO $pdo, $id){
        $userId = Auth::check();
        $data = json_decode(file_get_contents("php://input"), true);

        try{
            // Validación básica
            if (
                empty($data['nombre']) ||
                empty($data['nif']) ||
                empty($data['direccion'])
            ) {
                http_response_code(400);
                throw new Exception('Datos incompletos');
            }

            // Validar NIF
            if (!NifValidator::validar($data['nif'])) {
                http_response_code(400);
                throw new Exception('NIF / NIE / CIF no válido');
            }

            // Obtener empresa
            $stmt = $pdo->prepare("SELECT id FROM companies WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$company) {
                http_response_code(400);
                throw new Exception('Usuario sin empresa');
                return;
            }

            // Actualizar SOLO si pertenece a su empresa
            $stmt = $pdo->prepare("
                UPDATE clients
                SET nombre = :nombre,
                    nif = :nif,
                    direccion = :direccion
                WHERE id = :id AND company_id = :company_id
            ");

            $stmt->execute([
                'nombre' => $data['nombre'],
                'nif' => $data['nif'],
                'direccion' => $data['direccion'],
                'id' => $id,
                'company_id' => $company['id']
            ]);

            crearLog(
                $pdo,
                $userId,
                'CLIENTE_MODIFICADO',
                'Cliente actualizado ID: ' . $id
            );

            echo json_encode(['success' => true]);
        }catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_CLIENTE_UPDATE',
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