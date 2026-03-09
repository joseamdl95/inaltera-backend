<?php

/**
 * Crear un log encadenado (inalterable) por usuario
 */
function crearLog($pdo, $user_id, $evento, $descripcion = null) {

    // Obtener último log del usuario
    $stmt = $pdo->prepare("
        SELECT hash_actual, cadena
        FROM logs
        WHERE user_id = ?
        ORDER BY cadena DESC
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);

    $hash_anterior = $ultimo ? $ultimo['hash_actual'] : str_repeat('0', 64);
    $cadena = $ultimo ? (int)$ultimo['cadena'] + 1 : 1;

    $fecha = date('Y-m-d H:i:s');

    $data = json_encode([
        'user_id' => $user_id,
        'evento' => $evento,
        'descripcion' => $descripcion,
        'fecha' => $fecha,
        'cadena' => $cadena
    ], JSON_UNESCAPED_UNICODE);

    $hash_actual = hash('sha256', $data . $hash_anterior);

    $stmt = $pdo->prepare("
        INSERT INTO logs
        (id, user_id, evento, descripcion, cadena, hash_actual, hash_anterior, created_at)
        VALUES
        (UUID(), ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $user_id,
        $evento,
        $descripcion,
        $cadena,
        $hash_actual,
        $hash_anterior,
        $fecha
    ]);
}

function verificarIntegridadLogs(PDO $pdo, $userId) {

    $stmt = $pdo->prepare("
        SELECT cadena, hash_actual, hash_anterior, user_id, evento, descripcion, created_at
        FROM logs
        WHERE user_id = :user_id
        ORDER BY cadena ASC
    ");

    $stmt->execute([
        'user_id' => $userId
    ]);

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$logs) {
        return [
            'ok' => true,
            'total' => 0
        ];
    }

    $hashAnteriorEsperado = str_repeat('0', 64);

    foreach ($logs as $log) {

        $data = json_encode([
            'user_id' => $log['user_id'],
            'evento' => $log['evento'],
            'descripcion' => $log['descripcion'],
            'fecha' => $log['created_at'],
            'cadena' => (int)$log['cadena']
        ], JSON_UNESCAPED_UNICODE);

        $hashCalculado = hash('sha256', $data . $hashAnteriorEsperado);

        if ($hashCalculado !== $log['hash_actual']) {
            return [
                'ok' => false,
                'cadena' => $log['cadena'],
                'error' => 'Hash inválido'
            ];
        }

        if ($log['hash_anterior'] !== $hashAnteriorEsperado) {
            return [
                'ok' => false,
                'cadena' => $log['cadena'],
                'error' => 'Cadena rota'
            ];
        }

        $hashAnteriorEsperado = $log['hash_actual'];
    }

    return [
        'ok' => true,
        'total' => count($logs)
    ];
}