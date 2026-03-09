<?php
date_default_timezone_set('Europe/Madrid');
header("Access-Control-Allow-Origin: https://inaltera-frontend.vercel.app");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

//header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Utils/TwoFactor.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Utils/Auth.php';
require_once __DIR__ . '/../src/Controllers/UserController.php';
require_once __DIR__ . '/../src/Controllers/CompanyController.php';
require_once __DIR__ . '/../src/Controllers/InvoiceController.php';
require_once __DIR__ . '/../src/Controllers/ClientsController.php';
require_once __DIR__ . '/../src/Controllers/XmlController.php';
require_once __DIR__ . '/../src/Controllers/BillingController.php';
require_once __DIR__ . '/../src/Controllers/SifController.php';


$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Limpieza de ruta
$uri = str_replace('/index.php', '', $uri);
$uri = rtrim($uri, '/');

if ($uri === '/ping' && $method === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'message' => 'Backend INALTERA funcionando (Apache)'
    ]);
    exit;
}

if ($uri === '/auth/register' && $method === 'POST') {
    AuthController::register($pdo);
    exit;
}

if ($uri === '/auth/login') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }
    AuthController::login($pdo);
    exit;
}


if ($uri === '/me' && $method === 'GET') {
    UserController::me($pdo);
    exit;
}

if ($uri === '/auth/forgot-password' && $method === 'POST') {
    AuthController::forgotPassword($pdo);
    exit;
}

if ($uri === '/user/datos' && $method === 'PUT') {
    UserController::UpdateDatos($pdo);
    exit;
}

if ($uri === '/auth/reset-password' && $method === 'POST') {
    AuthController::resetPassword($pdo);
    exit;
}

if ($uri === '/user/email' && $method === 'PUT') {
    UserController::updateEmail($pdo);
    exit;
}

if ($uri === '/user/password' && $method === 'PUT') {
    UserController::updatePassword($pdo);
    exit;
}

// ... rutas de activar/desactivar 2FA ...

if ($uri === '/user/2fa/disable' && $method === 'POST'){
    UserController::disable2FA($pdo);
    exit;
}

if ($uri === '/auth/verify-2fa' && $method === 'POST'){
    AuthController::verify2FALogin($pdo);
    exit;
}


if ($uri === '/company') {
    if ($method === 'GET') {
        CompanyController::get($pdo);
    }

    if ($method === 'POST') {
        CompanyController::create($pdo);
    }

    if ($method === 'PUT') {
        CompanyController::update($pdo);
    }

    exit;
}

if($uri === '/sif'){
    if($method ==='POST'){
        SifController::store($pdo);
        exit;
    }

    if($method ==='GET'){
        SifController::index($pdo);
        exit;
    }
}

// 🔹 UPDATE SIF
if (preg_match('#^/sif/([a-z0-9\-]+)$#', $uri, $matches)) {
    if ($method === 'PUT') {
        SifController::update($pdo, $matches[1]);
        exit;
    }
}

// 🔹 SET DEFAULT
if (preg_match('#^/sif/([a-z0-9\-]+)/default$#', $uri, $matches)) {
    if ($method === 'POST') {
        SifController::setDefault($pdo, $matches[1]);
        exit;
    }
}

if ($uri === '/clients') {
    if ($method === 'GET') {
        ClientsController::index($pdo);
    }

    if ($method === 'POST') {
        ClientsController::store($pdo);
    }

    if (preg_match('#^/clients/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
        $id = $matches[1];

        if ($method === 'PUT') {
            ClientsController::update($pdo, $id);
        }

        exit;
    }

    exit;
}

if ($uri === '/billing/status' && $method === 'GET') {
    BillingController::status($pdo);
    exit;
}

if ($uri === '/billing/change-plan' && $method === 'POST') {
    BillingController::changePlan($pdo);
    exit;
}

if ($uri === '/invoices') {
    if ($method === 'GET') {
        InvoiceController::list($pdo);
    }

    if ($method === 'POST') {
        InvoiceController::create($pdo);
    }

    exit;
}

if (preg_match('#^/invoices/numero/(.+)$#', $uri, $matches) && $method === 'GET') {
    $numero = trim($matches[1]);
    InvoiceController::getByNumero($pdo, $numero);
    exit;
}


if ($uri === '/invoices/emit' && $method === 'POST') {
    InvoiceController::emit($pdo);
    exit;
}

if ($uri === '/invoices/download' && $method === 'GET') {
    InvoiceController::downloadPdf($pdo);
    exit;
}

if ($uri === '/invoices/download-xml' && $method === 'GET') {
    InvoiceController::downloadXml($pdo);
    exit;
}

if ($uri === '/invoices/download-xml-anulacion' && $method === 'GET') {
    InvoiceController::downloadXmlAnulacion($pdo);
    exit;
}


if ($uri === '/invoices/import-pdf' && $method === 'POST') {
    InvoiceController::importFromPdf($pdo);
    exit;
}

if ($uri === '/invoices/anular' && $method === 'POST') {
    InvoiceController::anular($pdo);
    exit;
}

if ($uri === '/invoices/anularBorrador' && $method === 'POST') {
    InvoiceController::anularBorrador($pdo);
    exit;
}

if($uri ==='/xml/verificar' && $method === 'POST'){
    XmlController::verificar($pdo);
    exit;
}

if ($uri === '/user/2fa/enable' && $method === 'POST'){
    UserController::enable2FA($pdo);
    exit;
}

if ($uri === '/user/2fa/verify' && $method === 'POST'){
    UserController::verify2FA($pdo);
    exit;
}

if ($uri === '/user/2fa/disable' && $method === 'POST'){
    UserController::disable2FA($pdo);
    exit;
}



http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado']);
