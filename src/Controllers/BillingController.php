<?php
require_once __DIR__ . '/../Utils/Logs.php';
require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/Billing.php';

class BillingController {

    public static function status(PDO $pdo) {

        $userId = Auth::check();

        // 🔹 obtener usuario
        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuario no encontrado']);
            return;
        }

        // 🔹 reset mensual
        $user = Billing::checkResetPeriodo($pdo, $user);

        // 🔹 planes
        $planes = [
            'FREE' => 5,
            'BASIC' => 10,
            'PRO' => 20
        ];

        $limite = $planes[$user['plan']] ?? 5;

        $usadas = (int)$user['facturas_mes'];

        echo json_encode([
            'plan' => $user['plan'],
            'usadas' => $usadas,
            'limite' => $limite,
            'restantes' => max(0, $limite - $usadas),
            'estado' => $user['estado_suscripcion']
        ]);
    }

    public static function changePlan(PDO $pdo) {

        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        if (!isset($data['plan'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Plan requerido']);
            return;
        }

        $planesValidos = ['FREE', 'BASIC', 'PRO'];

        if (!in_array($data['plan'], $planesValidos)) {
            http_response_code(400);
            echo json_encode(['error' => 'Plan inválido']);
            return;
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT plan FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $current = $stmt->fetch();
            $planAnterior = $current['plan'] ?? 'null';

            $stmt = $pdo->prepare("
                UPDATE users
                SET plan = :plan,
                    estado_suscripcion = 'ACTIVA'
                WHERE id = :id
            ");

            $stmt->execute([
                'plan' => $data['plan'],
                'id' => $userId
            ]);

            if ($planAnterior && $planAnterior !== $data['plan']){
                crearLog(
                    $pdo,
                    $userId,
                    'PLAN_CAMBIADO',
                    'Cambio de plan: ' . $planAnterior . ' → ' . $data['plan']
                );
            }

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => 'Plan actualizado correctamente'
            ]);
        }catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_CAMBIO_PLAN',
                $e->getMessage()
            );
            echo json_encode([
                'error' => 'Error cambiando plan',
                'debug' => $e->getMessage()
            ]);
        }
    }
}