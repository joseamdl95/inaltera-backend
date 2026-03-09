<?php

class Auth {

    private static string $secret;

    public static function check() {

        // 🔑 Cargar config AQUÍ (scope correcto)
        $jwtConfig = require __DIR__ . '/../../config/jwt.php';
        self::$secret = $jwtConfig['secret'];

        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        if (!isset($headers['Authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!isset($headers['Authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Token no proporcionado']);
            exit;
        }

        if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Formato de token inválido']);
            exit;
        }

        $token = $matches[1];

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            http_response_code(401);
            echo json_encode(['error' => 'Token mal formado']);
            exit;
        }

        [$header, $payload, $signature] = $parts;

        $validSignature = rtrim(strtr(
            base64_encode(
                hash_hmac(
                    'sha256',
                    $header . '.' . $payload,
                    self::$secret,
                    true
                )
            ),
            '+/',
            '-_'
        ), '=');

        if (!hash_equals($validSignature, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Firma inválida']);
            exit;
        }

        $data = json_decode(
            base64_decode(strtr($payload, '-_', '+/')),
            true
        );

        if ($data['exp'] < time()) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expirado']);
            exit;
        }

        return $data['sub'];
    }
}
