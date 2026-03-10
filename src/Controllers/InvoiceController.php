<?php

require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/Uuid.php';
require_once __DIR__ . '/../Utils/NoVerifactuXml.php';
require_once __DIR__ . '/../Utils/XmlValidator.php';
require_once __DIR__ . '/../Utils/PdfInvoiceGenerator.php';
require_once __DIR__ . '/../Utils/NifValidator.php';
require_once __DIR__ . '/../Utils/AIService.php';
require_once __DIR__ . '/../Utils/PdfInvoiceSealer.php';
require_once __DIR__ . '/../Utils/Billing.php';
require_once __DIR__ . '/../Utils/Logs.php';
require_once __DIR__ . '/../Utils/Invoices.php';

class InvoiceController {

    private static function isoDate(?string $date): string{
        if (!$date) return '';
        return date('Y-m-d\TH:i:s', strtotime($date));
    }

    public static function getByNumero(PDO $pdo, $numero) {
        $userId = Auth::check();

        // Empresa
        $stmt = $pdo->prepare("
            SELECT id FROM companies WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch();

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Empresa no encontrada']);
            return;
        }

        // Factura
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                c.nombre AS cliente_nombre,
                c.nif AS cliente_nif,
                c.direccion AS cliente_direccion,
                c.pais AS cliente_pais
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.numero = :numero
            AND i.company_id = :company_id
        ");
        $stmt->execute([
            'numero' => $numero,
            'company_id' => $company['id']
        ]);

        $invoice = $stmt->fetch();

        if (!$invoice) {
            http_response_code(404);
            echo json_encode(['error' => 'Factura no encontrada']);
            return;
        }

        // Líneas
        $stmt = $pdo->prepare("
            SELECT
                descripcion,
                cantidad,
                precio_unitario,
                iva_tipo,
                irpf_porcentaje
            FROM invoice_lines
            WHERE invoice_id = :id
        ");
        $stmt->execute(['id' => $invoice['id']]);

        $lines = $stmt->fetchAll();

        echo json_encode([
            'invoice' => $invoice,
            'lines' => $lines
        ]);
    }


    public static function create(PDO $pdo) {
        $pdo->beginTransaction();

        try {
           

            $userId = Auth::check();
            $data = json_decode(file_get_contents('php://input'), true);

            // 🔹 Obtener SIF (seleccionado o default)
            $sifId = $data['sif_id'] ?? null;

            if (!$sifId) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM sif_configs
                    WHERE user_id = :user_id
                    AND es_default = 1
                    LIMIT 1
                ");

                $stmt->execute(['user_id' => $userId]);
                $sif = $stmt->fetch();

                if (!$sif) {
                    throw new Exception("No hay SIF configurado");
                }

                $sifId = $sif['id'];
            }

            // Obtener empresa del usuario
            $stmt = $pdo->prepare("
                SELECT id FROM companies WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch();

            if (!$company) {
                http_response_code(400);
                throw new Exception('Empresa no encontrada');
            }

            $tipoFactura = $data['tipo_factura'] ?? 'F1';
            $facturaRectificadaId = $data['factura_rectificada_id'] ?? null;
            $motivoRectificacion = $data['motivo_rectificacion'] ?? null;


            if (!isset(
                $data['cliente_nombre'],
                $data['cliente_nif'],
                $data['cliente_pais'],
                $data['cliente_direccion'],
                $data['fecha_emision'],
                $data['lines'] 
            )) {
                http_response_code(400);
            throw new Exception('Datos incompletos');
            }

            if (strtotime($data['fecha_emision']) === false) {
                throw new Exception('Fecha inválida');
            }
            
            $fecha = DateTime::createFromFormat(
                'Y-m-d\TH:i',
                $data['fecha_emision'],
                new DateTimeZone('Europe/Madrid')
            );

            $ahora = new DateTime('now', new DateTimeZone('Europe/Madrid'));

            if ($fecha > $ahora) {
                throw new Exception("La fecha no puede ser futura");
            }

            if (!isset($data['lines']) || !is_array($data['lines']) || count($data['lines']) === 0) {
                http_response_code(400);
                throw new Exception('La factura debe contener al menos una línea');
            }

            if (!in_array($tipoFactura, ['F1','R1','R2'])) {
                throw new Exception("Tipo de factura inválido");
            }

            if ($tipoFactura !== 'F1') {

                if (!$facturaRectificadaId) {
                    throw new Exception("Debe indicar factura a rectificar");
                }

                if (empty(trim($motivoRectificacion ?? ''))) {
                throw new Exception("Debe indicar motivo de rectificación");
                }

                // Verificar que factura original existe y está EMITIDA
                $stmt = $pdo->prepare("
                    SELECT id, estado, tipo_factura
                    FROM invoices 
                    WHERE numero = :numero 
                    AND company_id = :company_id
                ");
                $stmt->execute([
                    'numero' => $facturaRectificadaId,
                    'company_id' => $company['id']
                ]);
                $original = $stmt->fetch();

                if (!$original) {
                    throw new Exception("Factura original no encontrada");
                }

                if ($original['estado'] !== 'EMITIDA') {
                    throw new Exception("Solo se pueden rectificar facturas emitidas");
                }

                if (in_array($original['tipo_factura'], ['R1','R2'])) {
                    throw new Exception("No se puede rectificar una factura rectificativa");
                }

                // Verificar que no esté ya rectificada
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM invoices
                    WHERE factura_rectificada_id = :id
                    AND tipo_factura IN ('R1','R2')
                    AND company_id = :company_id
                ");
                $stmt->execute([
                    'id' => $original['id'],
                    'company_id' => $company['id']
                ]);

                $yaRectificada = (int)$stmt->fetchColumn();

                if ($tipoFactura === 'R1' && $yaRectificada > 0) {
                    throw new Exception("Esta factura ya ha sido rectificada");
                }

            }       

            $lines = $data['lines'];

            foreach ($lines as $i=> $line) {
                if (!isset($line['concepto'], $line['cantidad'], $line['precio_unitario'], $line['iva'])) {
                    throw new Exception("Datos incompletos en línea ".($i+1));
                }

                if (empty(trim($line['concepto'] ?? ''))) {
                    throw new Exception("Concepto vacío en línea ".($i+1));
                }

                $cantidad = (float)$line['cantidad'];
                $precioUnitario = (float)$line['precio_unitario'];

                if ($tipoFactura === 'F1' || $tipoFactura === 'R1') {
                    if ($cantidad <= 0 || $precioUnitario <= 0) {
                        throw new Exception("Cantidad o precio inválidos en línea ".($i+1));
                    }
                }

                $ivasValidos = [0,4,10,21];

                if (!in_array((float)$line['iva'], array_map('floatval', $ivasValidos))){
                    throw new Exception("IVA inválido en línea ".($i+1));
                }
                
            }

            $base = 0;
            $cuotaIva = 0;
            $cuotaIrpf = 0;

            foreach ($lines as $i => $line) {
                $cantidad = (float) $line['cantidad'];
                $precioUnitario = (float) $line['precio_unitario'];

                $lineBase = $cantidad * $precioUnitario;

                $lineIva = (float) $line['iva'];
                $lineIrpf = (float) ($line['irpf'] ?? 0);

                $lineCuotaIva = round($lineBase * $lineIva / 100, 2);
                $lineCuotaIrpf = round($lineBase * $lineIrpf / 100, 2);

                $base += $lineBase;
                $cuotaIva += $lineCuotaIva;
                $cuotaIrpf += $lineCuotaIrpf;
            }

            $total = round($base + $cuotaIva - $cuotaIrpf, 2);


            if (!NifValidator::validar($data['cliente_nif'])) {
                http_response_code(400);
                throw new Exception('NIF / NIE / CIF no válido');
            }

            

            $fechaEmision = self::isoDate($data['fecha_emision']);

            $invoiceId = uuidv4();

            // 1. Buscar cliente por NIF
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE nif = :nif AND company_id = :company_id");
            $stmt->execute(['nif' => $data['cliente_nif'],'company_id' => $company['id']]);
            $client = $stmt->fetch();

            // 2. Si no existe, crear cliente
            if (!$client) {
                $clientId = uuidv4();

                $stmt = $pdo->prepare("
                    INSERT INTO clients (id, company_id, nif, nombre, direccion, pais)
                    VALUES (:id, :company_id, :nif, :nombre, :direccion, :pais)
                ");

                $stmt->execute([
                    'id' => $clientId,
                    'company_id' => $company['id'],
                    'nif' => $data['cliente_nif'],
                    'nombre' => $data['cliente_nombre'],
                    'direccion' => $data['cliente_direccion'],
                    'pais' => $data['cliente_pais'] ?? 'ES'
                ]);
            } else {
                $clientId = $client['id'];
            }


            $stmt = $pdo->prepare("
                INSERT INTO invoices
                (
                    id, company_id, client_id, sif_id, numero, fecha_emision,
                    base_imponible, cuota_iva, total,
                    estado, tipo_factura, origen, factura_rectificada_id, motivo_rectificacion
                )
                VALUES
                (
                    :id, :company_id, :client_id,  :sif_id, 'BORRADOR', :fecha_emision,
                    0, 0, 0,
                    'BORRADOR', :tipo_factura, 'FORM', :factura_rectificada_id, :motivo_rectificacion
                )
            ");

            $stmt->execute([
                'id' => $invoiceId,
                'company_id' => $company['id'],
                'client_id' => $clientId,
                'sif_id' => $sifId,
                'fecha_emision' => $fechaEmision,
                'tipo_factura' => $tipoFactura,
                'factura_rectificada_id' => $facturaRectificadaId,
                'motivo_rectificacion' => $motivoRectificacion
            ]);

            // === PASO 2: crear línea de factura ===
            
            foreach ($lines as $line) {
                $lineaId = uuidv4();

                $cantidad = (float) $line['cantidad'];
                $precioUnitario = (float) $line['precio_unitario'];
                    
                $baseLinea = $cantidad * $precioUnitario;

                $ivaLinea = (float) $line['iva'];
                $irpfLinea = (float) ($line['irpf'] ?? 0);

                if (in_array($tipoFactura, ['F1','R1'])) {
                    if ($cantidad <= 0 || $precioUnitario <= 0) {
                        throw new Exception('Cantidad y precio deben ser mayores que 0');
                    }
                }

                if ($irpfLinea < 0 || $irpfLinea > 100) {
                    throw new Exception('IRPF inválido');
                }

                $cuotaLinea = round($baseLinea * $ivaLinea / 100, 2);
                $cuotaIrpfLinea = round($baseLinea * $irpfLinea / 100, 2);

                $totalLinea = round($baseLinea + $cuotaLinea - $cuotaIrpfLinea, 2);

                $stmt = $pdo->prepare("
                    INSERT INTO invoice_lines
                    (
                        id, 
                        invoice_id, 
                        descripcion, 
                        cantidad, 
                        precio_unitario, 
                        base_imponible,
                        iva_tipo, 
                        iva_cuota, 
                        irpf_porcentaje, 
                        cuota_irpf
                    )
                    VALUES
                    (
                        :id, 
                        :invoice_id, 
                        :descripcion, 
                        :cantidad, 
                        :precio, 
                        :base,
                        :iva, 
                        :cuota_iva, 
                        :irpf, 
                        :cuota_irpf
                    )
                ");

                $stmt->execute([
                    'id' => $lineaId,
                    'invoice_id' => $invoiceId,
                    'descripcion' => $line['concepto'],
                    'cantidad' => $cantidad,
                    'precio' => $precioUnitario,
                    'base' => $baseLinea,
                    'iva' => $ivaLinea,
                    'cuota_iva' => $cuotaLinea,
                    'irpf' => $irpfLinea,
                    'cuota_irpf' => $cuotaIrpfLinea,
                ]);
            }
            

            $stmt = $pdo->prepare("
                SELECT
                    SUM(base_imponible) AS base,
                    SUM(iva_cuota) AS cuota_iva,
                    SUM(cuota_irpf) AS cuota_irpf
                FROM invoice_lines
                WHERE invoice_id = :invoice_id
            ");
            $stmt->execute(['invoice_id' => $invoiceId]);
            $totales = $stmt->fetch();
            
            $baseTotal = (float) ($totales['base'] ?? 0);
            $ivaTotal = (float) ($totales['cuota_iva'] ?? 0);
            $irpfTotal = (float) ($totales['cuota_irpf'] ?? 0);

            $total = $baseTotal + $ivaTotal - $irpfTotal;

            $stmt = $pdo->prepare("
                UPDATE invoices SET
                    base_imponible = :base,
                    cuota_iva = :cuota,
                    cuota_irpf = :irpf,
                    total = :total
                WHERE id = :id
            ");
            $stmt->execute([
                'base' => $baseTotal,
                'cuota' => $ivaTotal,
                'irpf' => $irpfTotal,
                'total' => $total,
                'id' => $invoiceId
            ]);

            echo json_encode([
                'ok' => true,
                'invoice_id' => $invoiceId,
                'base' => (float) $baseTotal,
                'cuota_iva' => (float) $ivaTotal,
                'irpf' => (float)$irpfTotal,
                'total' => (float) $total
            ]);

            crearLog(
                $pdo,
                $userId,
                'FACTURA_CREADA',
                'Factura borrador creada ID: ' . $invoiceId
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_FACTURA_CREAR',
                $e->getMessage()
            );
            echo json_encode([
                'error' => 'Error creando factura',
                'debug' => $e->getMessage()
            ]);
        }  
    }

    public static function emit(PDO $pdo) {
        $userId = Auth::check();

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

        $user = Billing::checkResetPeriodo($pdo, $user);

        if ($user['estado_suscripcion'] !== 'ACTIVA') {
            http_response_code(403);
            echo json_encode([
                'error' => 'Suscripción no activa'
            ]);
            return;
        }

        $planes = [
            'FREE' => 5,
            'BASIC' => 10,
            'PRO' => 20
        ];

        $limite = $planes[$user['plan']] ?? 5;

        if ($user['facturas_mes'] >= $limite) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Has alcanzado el límite mensual de tu plan'
            ]);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['invoice_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'invoice_id requerido']);
            return;
        }

        // Obtener empresa del usuario
        $stmt = $pdo->prepare("
            SELECT id, razon_social, nif, direccion, pais
            FROM companies
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch();

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Empresa no encontrada']);
            return;
        }

        // Obtener factura
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                c.nombre   AS cliente_nombre,
                c.nif      AS cliente_nif,
                c.direccion AS cliente_direccion,
                c.pais     AS cliente_pais
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.id = :id AND i.company_id = :company_id
        ");
        $stmt->execute([
            'id' => $data['invoice_id'],
            'company_id' => $company['id']
        ]);
        $invoice = $stmt->fetch();

        //Extraer el SIFID
        $stmt = $pdo->prepare("
            SELECT *
            FROM sif_configs
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $invoice['sif_id']
        ]);

        $sif = $stmt->fetch();

        if (!$sif) {
            throw new Exception("SIF no encontrado");
        }

        // 1. Identificar si es importada
        $isImported = ($invoice['origen'] === 'PDF');

        if (!$invoice || $invoice['estado'] !== 'BORRADOR') {
            http_response_code(400);
            echo json_encode(['error' => 'Factura no válida para emitir']);
            return;
        }

        //cargar lineas de factura
        $stmt = $pdo->prepare("
            SELECT
                descripcion,
                cantidad,
                precio_unitario,
                base_imponible,
                iva_tipo,
                iva_cuota,
                irpf_porcentaje,
                cuota_irpf
            FROM invoice_lines
            WHERE invoice_id = :invoice_id
        ");
        $stmt->execute([
            'invoice_id' => $invoice['id']
        ]);
        $invoice['lines'] = $stmt->fetchAll();

        if (empty($invoice['lines'])) {
            http_response_code(400);
            echo json_encode(['error' => 'La factura no tiene líneas']);
            return;
        }

        // Numeración segun tipo de factura
        $tipoFactura = $invoice['tipo_factura'];

        $prefijo = $tipoFactura === 'F1' ? 'F-' : 'R-';
        
        if ($isImported) {
            // Si es importada, el número definitivo es el que ya tiene menos el prefijo
            $numeroFactura =str_replace('BORRADOR_', '', $invoice['numero']);
        } else {
            if ($tipoFactura === 'F1') {
                $sqlFiltro = "AND tipo_factura = 'F1'";
            } else {
                $sqlFiltro = "AND tipo_factura IN ('R1','R2')";
            }
            $stmt = $pdo->prepare("
                SELECT MAX(CAST(SUBSTRING(numero, 3) AS UNSIGNED))
                FROM invoices
                WHERE company_id = :company_id 
                $sqlFiltro
                AND numero IS NOT NULL
                AND origen = 'FORM'
                AND numero != 'BORRADOR'
            ");

            $stmt->execute([
                'company_id' => $company['id']
            ]);

            $num = (int)$stmt->fetchColumn();
            $num++;
            $numeroFactura = $prefijo . str_pad($num, 6, '0', STR_PAD_LEFT);
        }
        
        //Preparar hash NoVerifactu
        $stmt = $pdo->prepare("
            SELECT ir.hash_actual, ir.cadena_hash
            FROM invoice_records ir
            INNER JOIN invoices i ON i.id = ir.invoice_id
            WHERE i.company_id = :company_id
            ORDER BY ir.cadena_hash DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'company_id' => $company['id']
        ]);
        $last = $stmt->fetch();

        $hashAnterior = $last['hash_actual']
            ?? str_repeat('0', 64);

        $cadenaAnterior = (int)($last['cadena_hash'] ?? 0);
        $cadenaActual = $cadenaAnterior + 1;

        $cadenaHash = implode('|', [
            $sif['software_nombre'] . '-' . $sif['version'],
            $company['nif'],
            $numeroFactura,
            self::isoDate($invoice['fecha_emision']),
            number_format((float)$invoice['cuota_iva'], 2, '.', ''),
            number_format((float)$invoice['total'], 2, '.', ''),
            $cadenaAnterior,
            $cadenaActual,
            $hashAnterior
        ]);

        $hashActual = hash('sha256', $cadenaHash);


        // Generar payload QR
            $qrPayload = implode('|', [
                $company['nif'],
                $numeroFactura,
                self::isoDate($invoice['fecha_emision']),
                number_format((float)$invoice['cuota_iva'], 2, '.', ''),
                number_format((float)$invoice['total'], 2, '.', ''),
                $hashActual
            ]);

        $pdo->beginTransaction();

        try{        

            // Emitir factura
            $stmt = $pdo->prepare("
                UPDATE invoices SET
                    numero = :numero,
                    estado = 'EMITIDA'
                WHERE id = :id
            ");
            $stmt->execute([
                'numero' => $numeroFactura,
                'id' => $invoice['id']
            ]);

            if ($tipoFactura === 'R2' && empty($invoice['factura_rectificada_id'])) {
                throw new Exception("Factura original no definida para rectificar");
            }

            if ($tipoFactura === 'R1') {

                $stmt = $pdo->prepare("
                    SELECT id, estado
                    FROM invoices
                    WHERE numero = :numero
                    AND company_id = :company_id
                ");

                $stmt->execute([
                    'numero' => $invoice['factura_rectificada_id'],
                    'company_id' => $company['id']
                ]);

                $original = $stmt->fetch();

                if (!$original) {
                    throw new Exception("Factura original no encontrada");
                }


                if ($original['estado'] !== 'EMITIDA') {
                    throw new Exception("Solo se puede anular una factura EMITIDA");
                }

                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET estado = 'ANULADA'
                    WHERE id = :id
                    AND company_id = :company_id
                ");

                $stmt->execute([
                    'id' => $original['id'],
                    'company_id' => $company['id']
                ]);
            }

            // 🔁 Recargar factura ya emitida (con numero real)
            $stmt = $pdo->prepare("
                SELECT 
                    i.*,
                    c.nombre   AS cliente_nombre,
                    c.nif      AS cliente_nif,
                    c.direccion AS cliente_direccion,
                    c.pais     AS cliente_pais
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                WHERE i.id = :id AND i.company_id = :company_id
            ");
            $stmt->execute([
                'id' => $invoice['id'],
                'company_id' => $company['id']
            ]);

            $invoice = $stmt->fetch();

            // 🔁 Volver a cargar líneas de la factura emitida
            $stmt = $pdo->prepare("
                SELECT
                    descripcion,
                    cantidad,
                    precio_unitario,
                    base_imponible,
                    iva_tipo,
                    iva_cuota,
                    irpf_porcentaje,
                    cuota_irpf
                FROM invoice_lines
                WHERE invoice_id = :invoice_id
            ");
            $stmt->execute([
                'invoice_id' => $invoice['id']
            ]);

            $invoice['lines'] = $stmt->fetchAll();


            // Generar XML NoVerifactu
            $numeroRectificada = null;
            $fechaRectificada = null;

            if (in_array($tipoFactura, ['R1','R2']) && !empty($invoice['factura_rectificada_id'])) {

                $stmt = $pdo->prepare("
                    SELECT numero, fecha_emision
                    FROM invoices
                    WHERE numero = :numero
                    AND company_id = :company_id
                ");

                $stmt->execute([
                    'numero' => $invoice['factura_rectificada_id'],
                    'company_id' => $company['id']
                ]);


                $original = $stmt->fetch();

                if (!$original) {
                    throw new Exception("Factura rectificada no encontrada");
                }

                $numeroRectificada = $original['numero'];
                $fechaRectificada = $original['fecha_emision'];
            }

            $xml = NoVerifactuXml::generate(
                $invoice,
                $company,
                [
                    'hash_actual' => $hashActual,
                    'hash_anterior' => $hashAnterior,
                    'cadena_hash' => $cadenaActual,
                    'tipo_factura' => $tipoFactura,
                    'factura_rectificada_id' => $numeroRectificada,
                    'motivo_rectificacion' => $invoice['motivo_rectificacion'],
                    'sif' => $sif
                ]   
            );

            // Validar XML con XSD
            $validation = XmlValidator::validate(
                $xml,
                __DIR__ . '/../../xsd/noverifactu_v1.xsd'
            );
            if (!$validation['valid']) {
                throw new Exception('XML inválido');
            }
            
            $estadoRecord = ($invoice['tipo_factura'] === 'F1') ? 'ALTA' : 'RECTIFICACION';

            // Guardar registro NoVerifactu
            $stmt = $pdo->prepare("
                INSERT INTO invoice_records
                (id, invoice_id, xml_content, xsd_version, hash_actual, hash_anterior, cadena_hash, qr_payload, estado)
                VALUES
                (:id, :invoice_id, :xml, 'v1', :hash_actual, :hash_anterior, :cadena_hash, :qr, :estado)
            ");
            $stmt->execute([
                'id' => uuidv4(),
                'invoice_id' => $invoice['id'],
                'xml'=> $xml,
                'hash_actual' => $hashActual,
                'hash_anterior' => $hashAnterior,
                'cadena_hash' => $cadenaActual,
                'qr' => $qrPayload,
                'estado' => $estadoRecord
            ]);

            //Guardar XML
            $xmlDir = __DIR__ . '/../../storage/xml_emitidos';
            $xmlFilename = 'registro_' . $company['nif'] . '_' . $numeroFactura . '.xml';
            $xmlFullPath = $xmlDir . '/' . $xmlFilename;

            file_put_contents($xmlFullPath, $xml);

            $key = "xml_emitidos/" . $xmlFilename;
            $xmlRelative = R2Storage::upload($xmlFullPath, $key);

            unlink($xmlFullPath);

            
            // Generar PDF final
            // 1. Definir rutas para el archivo sellado
            $outputDir = __DIR__ . '/../../storage/facturas_emitidas';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            $nombreArchivoOriginal = basename($invoice['pdf_original']);
            $nuevoNombreSellado = str_replace(['BORRADOR_', 'import_'], '', $nombreArchivoOriginal);
            $nuevoNombreSellado = 'factura_' . $company['nif'] . '_' . $numeroFactura . '.pdf'; // Nombre limpio y profesional

            // Ruta completa para que el sistema escriba en el disco
            $pathDestinoFisico = $outputDir . '/' . $nuevoNombreSellado;

            if ($isImported) {

                // descargar PDF desde R2 temporalmente
                $tempOriginal = sys_get_temp_dir() . '/' . basename($invoice['pdf_original']);

                file_put_contents(
                    $tempOriginal,
                    file_get_contents($invoice['pdf_original'])
                );

                // sellar el PDF descargado
                $pdfPath = PdfInvoiceSealer::sealOriginal(
                    $tempOriginal,
                    $pathDestinoFisico,
                    ['hash_actual' => $hashActual],
                    $qrPayload
                );

                // eliminar temporal
                if (file_exists($tempOriginal)) {
                    unlink($tempOriginal);
                }

            } else {
                // GENERAR DESDE CERO
                $pdfPath = PdfInvoiceGenerator::generate($invoice, $company, ['hash_actual' => $hashActual, 'sif' => $sif], $qrPayload);
                // (Asegúrate que Generator también guarde en la misma carpeta o mueve el archivo)
            }

            // Convertir a ruta relativa y subir a R2
            $key = "facturas_emitidas/" . basename($pdfPath);
            $pdfRelative = R2Storage::upload($pdfPath, $key);

            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
            // Guardar ruta PDF y XML
            $stmt = $pdo->prepare("
                UPDATE invoices
                SET pdf_sellado = :pdf,
                    xml_sellado = :xml
                WHERE id = :id
            ");

            $stmt->execute([
                'pdf' => $pdfRelative,
                'xml' => $xmlRelative,
                'id' => $invoice['id']
            ]);

            $stmt = $pdo->prepare("
                UPDATE users
                SET facturas_mes = facturas_mes + 1
                WHERE id = :id
            ");

            $stmt->execute(['id' => $user['id']]);

            crearLog(
                $pdo,
                $userId,
                'FACTURA_EMITIDA',
                'Factura ' . $numeroFactura . ' emitida | Hash: ' . $hashActual
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'numero' => $numeroFactura,
                'hash' => $hashActual,
                'cadena' => $cadenaActual
            ]);

        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_FACTURA_EMITIR',
                $e->getMessage()
            );
            echo json_encode([
                'error' => 'Error al emitir factura', 
                'debug' => $e->getMessage()
            ]);
            return;
        }
    }

    public static function downloadPdf($pdo){
        try {

            $userId = Auth::check();

            if (!isset($_GET['id'])) {
                throw new Exception("ID requerido");
            }

            $id = $_GET['id'];

            $stmt = $pdo->prepare("
                SELECT pdf_sellado, estado
                FROM invoices
                WHERE id = ?
            ");

            $stmt->execute([$id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception("Factura no encontrada");
            }

            if (!in_array($invoice['estado'], ['EMITIDA', 'ANULADA'])) {
                throw new Exception("La factura debe estar EMITIDA o ANULADA");
            }

            if (empty($invoice['pdf_sellado'])) {
                throw new Exception("PDF sellado no disponible");
            }

            $pdfUrl = $invoice['pdf_sellado'];

            crearLog(
                $pdo,
                $userId,
                'PDF_DESCARGADO',
                'Descarga PDF factura: ' . $pdfUrl
            );

            echo json_encode([
                "url" => $invoice['pdf_sellado']
            ]);
            exit;

        } catch (Exception $e) {

            http_response_code(400);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_PDF_DESCARGAR',
                $e->getMessage()
            );
            echo json_encode([
                "error" => "Error descargando PDF",
                "debug" => $e->getMessage()
            ]);
        }
    }

    public static function downloadXml($pdo) {
        try {

            $userId = Auth::check();

            if (!isset($_GET['id'])) {
                throw new Exception("ID requerido");
            }

            $invoiceId = $_GET['id'];

            // 🔹 Obtener empresa del usuario
            $stmt = $pdo->prepare("
                SELECT id 
                FROM companies 
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch();

            if (!$company) {
                throw new Exception("Empresa no encontrada");
            }

            // 🔹 Obtener ruta XML de la factura
            $stmt = $pdo->prepare("
                SELECT *
                FROM invoices
                WHERE id = :id
                AND company_id = :company_id
            ");

            $stmt->execute([
                'id' => $invoiceId,
                'company_id' => $company['id']
            ]);

            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception("Factura no encontrada");
            }

            if ($invoice['estado'] == 'BORRADOR' || $invoice['estado'] == 'BORRADOR_ANULADO') {
               throw new Exception("Factura no emitida");
            }

            if (empty($invoice['xml_sellado'])) {
                throw new Exception("XML no disponible");
            }

            $xmlUrl = $invoice['xml_sellado'];

            crearLog(
                $pdo,
                $userId,
                'XML_DESCARGADO',
                'Descarga XML factura: ' . $xmlUrl
            );

            echo json_encode([
                "url" => $xmlUrl
            ]);
            exit;

        } catch (Exception $e) {

            http_response_code(400);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_XML_DESCARGAR',
                $e->getMessage()
            );
            echo json_encode([
                "error" => "Error descargando XML",
                "debug" => $e->getMessage()
            ]);
        }
    }

    public static function downloadXmlAnulacion($pdo) {
        try {

            $userId = Auth::check();

            if (!isset($_GET['id'])) {
                throw new Exception("ID requerido");
            }

            $invoiceId = $_GET['id'];

            // 🔹 Obtener empresa del usuario
            $stmt = $pdo->prepare("
                SELECT id 
                FROM companies 
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch();

            if (!$company) {
                throw new Exception("Empresa no encontrada");
            }

            // 🔹 Obtener ruta XML de la factura
            $stmt = $pdo->prepare("
                SELECT estado, xml_anulado
                FROM invoices
                WHERE id = :id
                AND company_id = :company_id
            ");

            $stmt->execute([
                'id' => $invoiceId,
                'company_id' => $company['id']
            ]);

            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception("Factura no encontrada");
            }

            if ($invoice['estado'] !== 'ANULADA') {
               throw new Exception("Factura no anulada");
            }

            if (empty($invoice['xml_anulado'])) {
                throw new Exception("XML no disponible");
            }

            $xmlUrl = $invoice['xml_anulado'];

            crearLog(
                $pdo,
                $userId,
                'XML_DESCARGADO',
                'Descarga XML anulación factura: ' . $xmlUrl
            );

            echo json_encode([
                "url" => $xmlUrl
            ]);

        } catch (Exception $e) {

            http_response_code(400);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_XML_DESCARGAR',
                $e->getMessage()
            );
            echo json_encode([
                "error" => "Error descargando XML",
                "debug" => $e->getMessage()
            ]);
        }
    }

    public static function list($pdo){
        try {

            $userId = Auth::check();

            $logsIntegrity = verificarIntegridadLogs($pdo, $userId);

            $stmt = $pdo->prepare("
                SELECT id 
                FROM companies 
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch();

            if (!$company) {
                http_response_code(400);
                echo json_encode(['error' => 'Empresa no encontrada']);
                return;
            };

            $facturasIntegrity = verificarIntegridadFacturas($pdo, $company['id']);

            $desde = $_GET['desde'] ?? null;
            $hasta = $_GET['hasta'] ?? null;
            $search = $_GET['search'] ?? null;

            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $sql="
                SELECT DISTINCT
                    i.id,
                    i.numero,
                    i.fecha_emision,
                    c.nif AS cliente_nif,
                    i.total,
                    i.estado,
                    i.pdf_original,
                    i.pdf_sellado
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                LEFT JOIN invoice_lines il ON il.invoice_id = i.id
                WHERE i.company_id = :company_id
            ";
            $params =[];

            $params['company_id'] = $company['id'];
            

            if (!empty($search)) {
                $sql .= "
                    AND (
                        i.numero LIKE :search
                        OR c.nombre LIKE :search
                        OR c.nif LIKE :search
                        OR il.descripcion LIKE :search
                    )
                ";
                $params['search'] = '%' . $search . '%';
            }


            if (!empty($desde)) {
                $sql .= " AND i.fecha_emision >= :desde";
                $params['desde'] = $desde;
            }

            if (!empty($hasta)) {
                $sql .= " AND i.fecha_emision <= :hasta";
                $params['hasta'] = $hasta;
            }

            $countSql = "
                SELECT COUNT(DISTINCT i.id) 
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                LEFT JOIN invoice_lines il ON il.invoice_id = i.id
                WHERE i.company_id = :company_id
            ";

            $countParams = ['company_id' => $company['id']];

            if (!empty($desde)) {
                $countSql .= " AND i.fecha_emision >= :desde";
                $countParams['desde'] = $desde;
            }

            if (!empty($hasta)) {
                $countSql .= " AND i.fecha_emision <= :hasta";
                $countParams['hasta'] = $hasta;
            }

            if (!empty($search)) {
                $countSql .= "
                    AND (
                        i.numero LIKE :search
                        OR c.nombre LIKE :search
                        OR c.nif LIKE :search
                        OR il.descripcion LIKE :search
                    )
                ";
                $countParams['search'] = '%' . $search . '%';
            
            }


            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $totalRows = (int)$countStmt->fetchColumn();

            $sql .= " ORDER BY i.fecha_emision DESC LIMIT :limit OFFSET :offset";

            $params['limit'] = $limit;
            $params['offset'] = $offset;



            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                if ($key === 'limit' || $key === 'offset') {
                    $stmt->bindValue(':' . $key, (int)$value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':' . $key, $value);
                }
            }

            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


            $result = array_map(function ($inv) {

                $isEmitida = $inv['estado'] === 'EMITIDA'||  $inv['estado'] ==='ANULADA' ;
                $hasPdf = !empty($inv['pdf_sellado']);

                return [
                    'id' => $inv['id'],
                    'numero' => $inv['numero'],
                    'fecha_emision' => $inv['fecha_emision'],
                    'cliente_nif' => $inv['cliente_nif'],
                    'total' => $inv['total'],
                    'estado' => $inv['estado'],

                    // 👇 NUEVO
                    'pdf_disponible' => $isEmitida && $hasPdf,

                    // 👇 Solo si existe
                    'download_url' => ($isEmitida && $hasPdf)
                        ? "/api/invoices/download?id=" . $inv['id']
                        : null
                ];

            }, $rows);

            echo json_encode([
                'data' => $result,
                'page' => $page,
                'limit' => $limit,
                'total' => $totalRows,
                'total_pages' => ceil($totalRows / $limit),
                'logs_integrity' => $logsIntegrity['ok'],
                'facturas_integrity' => $facturasIntegrity['ok']
            ]);


        } catch (Exception $e) {

            http_response_code(500);
            echo json_encode([
                "error" => "Error listando facturas",
                "debug" => $e->getMessage()
            ]);
        }       
    }

     public static function anular(PDO $pdo) {

        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['invoice_id'])) {
          http_response_code(400);
          echo json_encode(['error' => 'invoice_id requerido']);
          return;
        }

        if (!isset($data['motivo']) || trim($data['motivo']) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Motivo requerido']);
            return;
        }

        $motivo = trim($data['motivo']);


        // 🔹 Obtener empresa del usuario
        $stmt = $pdo->prepare("
            SELECT id, razon_social, nif
            FROM companies
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch();

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Empresa no encontrada']);
            return;
        }

        // 🔹 Obtener factura
        $stmt = $pdo->prepare("
            SELECT *
            FROM invoices
            WHERE id = :id
            AND company_id = :company_id
        ");
        $stmt->execute([
            'id' => $data['invoice_id'],
            'company_id' => $company['id']
        ]);

        $invoice = $stmt->fetch();

        if (!$invoice) {
            http_response_code(400);
            echo json_encode(['error' => 'Factura no encontrada']);
            return;
        }

        if ($invoice['estado'] !== 'EMITIDA') {
            http_response_code(400);
            echo json_encode(['error' => 'Solo se pueden anular facturas emitidas']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT s.software_nombre, s.version
            FROM sif_configs s
            INNER JOIN invoices i ON i.sif_id = s.id
            WHERE i.id = :invoice_id
            LIMIT 1
        ");

        $stmt->execute([
            'invoice_id' => $invoice['id']
        ]);

        $sif = $stmt->fetch();

        if (!$sif) {
            throw new Exception("SIF no encontrado");
        }

        // 🔹 Obtener último hash por empresa
        $stmt = $pdo->prepare("
            SELECT ir.hash_actual, ir.cadena_hash
            FROM invoice_records ir
            INNER JOIN invoices i ON i.id = ir.invoice_id
            WHERE i.company_id = :company_id
            ORDER BY ir.cadena_hash DESC
            LIMIT 1
        ");
        $stmt->execute([
            'company_id' => $company['id']
        ]);

        $last = $stmt->fetch();

        $hashAnterior = $last['hash_actual'] ?? str_repeat('0', 64);
        $cadenaAnterior = (int)($last['cadena_hash'] ?? 0);
        $cadenaActual = $cadenaAnterior + 1;

        // 🔹 Construir cadena hash para anulación
        $cadenaHash = implode('|', [
            $sif['software_nombre'] . '-' . $sif['version'],
            $company['nif'],
            $invoice['numero'],
            self::isoDate($invoice['fecha_emision']),
            number_format((float)$invoice['cuota_iva'], 2, '.', ''),
            number_format((float)$invoice['total'], 2, '.', ''),
            $cadenaAnterior,
            $cadenaActual,
            $hashAnterior
        ]);

        $hashActual = hash('sha256', $cadenaHash);

        // 🔹 Generar XML simple de anulación
        $xmlAnulacion = '<?xml version="1.0" encoding="UTF-8"?>';
        $xmlAnulacion .= '<Anulacion>';
        $xmlAnulacion .= '<NumeroFactura>' . $invoice['numero'] . '</NumeroFactura>';
        $xmlAnulacion .= '<FechaEmision>' . self::isoDate($invoice['fecha_emision']) . '</FechaEmision>';
        $xmlAnulacion .= '<FechaAnulacion>' . date('Y-m-d\TH:i:s') . '</FechaAnulacion>';
        $xmlAnulacion .= '<Emisor>';
        $xmlAnulacion .= '<RazonSocial>' . htmlspecialchars($company['razon_social']) . '</RazonSocial>';
        $xmlAnulacion .= '<NIF>' . $company['nif'] . '</NIF>';
        $xmlAnulacion .= '</Emisor>';
        $xmlAnulacion .= '<Totales>';
        $xmlAnulacion .= '<CuotaIVA>' . number_format((float)$invoice['cuota_iva'], 2, '.', '') . '</CuotaIVA>';
        $xmlAnulacion .= '<Total>' . number_format((float)$invoice['total'], 2, '.', '') . '</Total>';
        $xmlAnulacion .= '</Totales>';
        $xmlAnulacion .= '<NoVerifactu>';
        $xmlAnulacion .= '<Motivo>' . htmlspecialchars($motivo) . '</Motivo>';
        $xmlAnulacion .= '<VersionSIF>' . htmlspecialchars($sif['software_nombre'].'-'.$sif['version']) . '</VersionSIF>';
        $xmlAnulacion .= '<HashAnterior>' . $hashAnterior . '</HashAnterior>';
        $xmlAnulacion .= '<HashActual>' . $hashActual . '</HashActual>';
        $xmlAnulacion .= '<Cadena>' . $cadenaActual . '</Cadena>';
        $xmlAnulacion .= '</NoVerifactu>';
        $xmlAnulacion .= '</Anulacion>';

        $pdo->beginTransaction();

        try {

           // 🔹 Insertar nuevo registro inmutable
           $stmt = $pdo->prepare("
               INSERT INTO invoice_records
               (id, invoice_id, xml_content, xsd_version, hash_actual, hash_anterior, cadena_hash, estado)
                VALUES
                (:id, :invoice_id, :xml, 'v1', :hash_actual, :hash_anterior, :cadena_hash, 'ANULACION')
            ");

            $stmt->execute([
                'id' => uuidv4(),
                'invoice_id' => $invoice['id'],
                'xml' => $xmlAnulacion,
                'hash_actual' => $hashActual,
                'hash_anterior' => $hashAnterior,
                'cadena_hash' => $cadenaActual
            ]);

            $xmlDir = __DIR__ . '/../../storage/xml_anulados';
            $xmlFilename = 'registroAnulacion_' . $company['nif'] . '_' . $invoice['numero'] . '.xml';
            $xmlFullPath = $xmlDir . '/' . $xmlFilename;

            file_put_contents($xmlFullPath, $xmlAnulacion);

            $key = "xml_anulados/" . $xmlFilename;
            $xmlRelative = R2Storage::upload($xmlFullPath, $key);

            if (file_exists($xmlFullPath)) {
                unlink($xmlFullPath);
            }

            // 🔹 Actualizar estado funcional
            $stmt = $pdo->prepare("
                UPDATE invoices
                SET estado = 'ANULADA', xml_anulado = :xmlAnulacionRuta
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => $invoice['id'],
                'xmlAnulacionRuta' => $xmlRelative
            ]);

            crearLog(
                $pdo,
                $userId,
                'FACTURA_ANULADA',
                'Factura ' . $invoice['numero'] . ' anulada | Hash: ' . $hashActual
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => 'Factura anulada correctamente',
                'hash' => $hashActual,
                'cadena' => $cadenaActual
            ]);

        } catch (Throwable $e) {

            $pdo->rollBack();

            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_FACTURA_ANULAR',
                $e->getMessage()
            );
            echo json_encode([
                'error' => 'Error al anular factura',
                'debug' => $e->getMessage()
            ]);
        }
    }

    public static function anularBorrador(PDO $pdo){

        $userId = Auth::check();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['invoice_id'])) {
          http_response_code(400);
          echo json_encode(['error' => 'invoice_id requerido']);
          return;
        }

        // 🔹 Obtener empresa del usuario
        $stmt = $pdo->prepare("
            SELECT id, razon_social, nif
            FROM companies
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $company = $stmt->fetch();

        if (!$company) {
            http_response_code(400);
            echo json_encode(['error' => 'Empresa no encontrada']);
            return;
        }

        // 🔹 Obtener factura
        $stmt = $pdo->prepare("
            SELECT *
            FROM invoices
            WHERE id = :id
            AND company_id = :company_id
        ");
        $stmt->execute([
            'id' => $data['invoice_id'],
            'company_id' => $company['id']
        ]);

        $invoice = $stmt->fetch();

        if (!$invoice) {
            http_response_code(400);
            echo json_encode(['error' => 'Factura no encontrada']);
            return;
        }

        try{
            $pdo->beginTransaction();
            // 🔹 Actualizar estado funcional
            $stmt = $pdo->prepare("
                UPDATE invoices
                SET estado = 'BORRADOR_ANULADO'
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => $invoice['id']
            ]);

            crearLog(
                $pdo,
                $userId,
                'BORRADOR_ANULADO',
                'Factura borrador anulada ID: ' . $invoice['id']
            );

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => 'Factura anulada correctamente'
            ]);

        } catch (Throwable $e) {

            $pdo->rollBack();

            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_BORRADOR_ANULAR',
                $e->getMessage()
            );
            echo json_encode([
                'error' => 'Error al anular factura',
                'debug' => $e->getMessage()
            ]);
        }
    }

    public static function importFromPdf(PDO $pdo) {
        $userId = Auth::check();

        $sifId = $_POST['sif_id'] ?? null;

        // fallback: usar SIF por defecto si no viene
        if (!$sifId) {
            $stmt = $pdo->prepare("
                SELECT id FROM sif_configs 
                WHERE user_id = :user_id AND es_default = 1 
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $defaultSif = $stmt->fetch();

            $sifId = $defaultSif['id'] ?? null;
        }

        if (!isset($_FILES['pdf'])) {
            http_response_code(400);
            echo json_encode(['error' => 'PDF no recibido']);
            return;
        }

        try {
            // 1. Obtener datos de la empresa (NIF necesario para el nombre del archivo)
            $stmt = $pdo->prepare("SELECT id, nif FROM companies WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $company = $stmt->fetch();
            if (!$company) throw new Exception("Empresa no configurada");

            // 2. Guardado Temporal y Preparación
            $tempBase = __DIR__ . '/../../storage/pdfs_original';
            if (!is_dir($tempBase)) mkdir($tempBase, 0777, true);

            $uploadedRaw = $tempBase . '/' . uniqid('raw_') . '.pdf';
            move_uploaded_file($_FILES['pdf']['tmp_name'], $uploadedRaw);
            
            $tempPath = $tempBase . '/' . uniqid('temp_') . '.pdf';

            // 2.1. Limpieza de PDF (Ghostscript dinámico para Win/Linux)
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $gsCmd = $isWindows ? 'gswin64c' : 'gs';

            // Ejecutamos la limpieza (el archivo resultante en $tempPath será compatible con FPDI)
            $returnCode = -1;
            $gsCommand = "$gsCmd -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dQUIET -dBATCH -sOutputFile=" . escapeshellarg($tempPath) . " " . escapeshellarg($uploadedRaw) . " 2>&1";
            
            exec($gsCommand, $output, $returnCode);

            // 2.2. Verificación y Limpieza de archivos temporales
            if ($returnCode === 0 && file_exists($tempPath)) {
                // Si funcionó, eliminamos el original "sucio" inmediatamente
                if (file_exists($uploadedRaw)) unlink($uploadedRaw);
            } else {
                // Si GS falla, usamos el original como backup (aunque FPDI pueda dar error luego)
                $tempPath = $uploadedRaw; 
            }

            // 3. IA: Extracción de datos (Forzamos la extracción del número)
            $dataIA = AIService::extractInvoiceData($tempPath);

            
            $rectificadaNum = $dataIA['factura_rectificada_num'] ?? null;

            // Buscamos la factura original para obtener su ID y su HASH (necesario para Verifactu)
            if ($rectificadaNum) {
                $stmt = $pdo->prepare("SELECT id, numero, estado FROM invoices WHERE numero = :num AND company_id = :cid");
                $stmt->execute([':num' => $rectificadaNum, ':cid' => $company['id']]);
                $original = $stmt->fetch();
            }
            
            // Obtenemos el número extraído o usamos uno por defecto si falla
            $numFacturaExtraido = $dataIA['factura']['numero'] ?? 'SIN_NUMERO';
            
            // 4. Renombrado Final: import_NifEmisor_NumeroDeFactura.pdf
            // Limpiamos el número de factura de caracteres raros (/, \, *, etc.)
            $numFacturaLimpio = preg_replace('/[^A-Za-z0-9_\-]/', '_', $numFacturaExtraido);
            $finalFileName = "import_{$company['nif']}_{$numFacturaLimpio}.pdf";
            $finalPath = $tempBase . '/' . $finalFileName;
            
            // Si ya existe uno igual, le añade un prefijo único para no sobreescribir
            if (file_exists($finalPath)) {
                $finalFileName = "import_{$company['nif']}_{$numFacturaLimpio}_" . time() . ".pdf";
                $finalPath = $tempBase . '/' . $finalFileName;
            }

            rename($tempPath, $finalPath);

            $key = "pdfs_original/" . $finalFileName;
            $rutaRelativaBDD = R2Storage::upload($finalPath, $key);

            if (file_exists($finalPath)) {
                unlink($finalPath);
            }

            $pdo->beginTransaction();

            // 5. Gestión de Cliente (Búsqueda por NIF extraído)
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE nif = :nif AND company_id = :company_id");
            $stmt->execute([':nif' => $dataIA['cliente']['nif'], ':company_id' => $company['id']]);
            $client = $stmt->fetch();
            
            $clientId = $client ? $client['id'] : uuidv4();
            if (!$client) {
                $stmt = $pdo->prepare("INSERT INTO clients (id, nif, nombre, direccion, pais, company_id) VALUES (?,?,?,?,?,?)");
                $stmt->execute([
                    $clientId, 
                    $dataIA['cliente']['nif'], 
                    $dataIA['cliente']['nombre'] ?: 'Cliente Nuevo', 
                    $dataIA['cliente']['direccion'] ?: '', 
                    $dataIA['cliente']['pais'] ?: 'ES',
                    $company['id']
                ]);
            }

            // 6. Crear Factura en estado BORRADOR
            $invoiceId = uuidv4();
            $stmt = $pdo->prepare("
                INSERT INTO invoices (
                    id, company_id, client_id, sif_id, numero, fecha_emision, 
                    estado, origen, pdf_original, tipo_factura,
                    base_imponible, cuota_iva, cuota_irpf, total,
                    factura_rectificada_id,motivo_rectificacion
                ) VALUES (
                    :id, :comp, :cli, :sif_id, :num, :fecha, 
                    'BORRADOR', 'PDF', :pdf, :tipo,
                    0, 0, 0, 0,
                    :id_rectificada, :motivo
                )
            ");
            
            $stmt->execute([
                ':id'    => $invoiceId,
                ':comp'  => $company['id'],
                ':cli'   => $clientId,
                ':sif_id' => $sifId,
                ':num'   => 'BORRADOR_' . $numFacturaLimpio, // Usamos el número real prefijado
                ':fecha' => $dataIA['factura']['fecha_emision'] ?? date('Y-m-d'),
                ':pdf'   => $rutaRelativaBDD,
                ':tipo'  => $dataIA['tipo_factura'] ?? 'F1',
                ':id_rectificada' => $original['numero']?? null,
                ':motivo' => $dataIA['motivo_rectificacion'] ?? null
            ]);

            // 7. Insertar Líneas
            foreach ($dataIA['lineas'] as $l) {
                $cantidad = floatval($l['cantidad'] ?: 1);
                $precio = floatval($l['precio_unitario'] ?: 0);
                $baseLinea = $cantidad * $precio;
                $tipoIva = intval($l['iva'] ?: 21);
                $cuotaIva = round($baseLinea * ($tipoIva / 100), 2);
                $porcIrpf = intval($l['irpf'] ?: 0);
                $cuotaIrpf = round($baseLinea * ($porcIrpf / 100), 2);

                $stmt = $pdo->prepare("
                    INSERT INTO invoice_lines (
                        id, invoice_id, descripcion, cantidad, precio_unitario, 
                        base_imponible, iva_tipo, iva_cuota, irpf_porcentaje, cuota_irpf
                    ) VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    uuidv4(), $invoiceId, $l['concepto'], $cantidad, $precio,
                    $baseLinea, $tipoIva, $cuotaIva, $porcIrpf, $cuotaIrpf
                ]);
            }

            // 8. Recalcular Totales
            $stmt = $pdo->prepare("SELECT SUM(base_imponible) as b, SUM(iva_cuota) as v, SUM(cuota_irpf) as r FROM invoice_lines WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            $tot = $stmt->fetch();

            $b = $tot['b'] ?: 0;
            $v = $tot['v'] ?: 0;
            $r = $tot['r'] ?: 0;

            $stmt = $pdo->prepare("UPDATE invoices SET base_imponible = ?, cuota_iva = ?, cuota_irpf = ?, total = ? WHERE id = ?");
            $stmt->execute([$b, $v, $r, round($b + $v - $r, 2), $invoiceId]);

            crearLog(
                $pdo,
                $userId,
                'FACTURA_IMPORTADA',
                'Factura importada desde PDF: ' . $finalFileName
            );
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'invoice_id' => $invoiceId, 
                'new_filename' => $finalFileName,
                'pdf_path' => $rutaRelativaBDD
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_FACTURA_IMPORTAR',
                $e->getMessage()
            );
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
