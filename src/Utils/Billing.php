<?php

class Billing {

    public static function checkResetPeriodo(PDO $pdo, $user) {

        $mesActual = date('Y-m');

        if ($user['periodo_actual'] !== $mesActual) {

            $stmt = $pdo->prepare("
                UPDATE users
                SET facturas_mes = 0,
                    periodo_actual = :periodo
                WHERE id = :id
            ");

            $stmt->execute([
                'periodo' => $mesActual,
                'id' => $user['id']
            ]);

            $user['facturas_mes'] = 0;
            $user['periodo_actual'] = $mesActual;
        }

        return $user;
    }
}