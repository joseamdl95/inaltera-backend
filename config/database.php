<?php

$host = 'sql111.infinityfree.com';
$db   = 'if0_41321723_inalteradb';
$user = 'if0_41321723';
$pass = '3T9hFEeFRv7HT';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
   // echo json_encode(['error' => 'Error de conexión a la base de datos']);
   echo json_encode([
        'error' => 'Error de conexión a la base de datos',
        'detalle' => $e->getMessage()
    ]);
    exit;
}
