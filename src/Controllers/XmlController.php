<?php

require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/Logs.php';

class XmlController {

    public static function verificar(PDO $pdo) {

        $userId = Auth::check();

        $logsIntegrity = verificarIntegridadLogs($pdo, $userId);

        try {

            // Empresa del usuario
            $stmt = $pdo->prepare("
                SELECT id, nif 
                FROM companies 
                WHERE user_id = :user_id
            ");

            $stmt->execute(['user_id' => $userId]);
            $company = $stmt->fetch();

            if (!$company) {
                http_response_code(404);
                throw new Exception('Empresa no configurada');
            }

            if (!isset($_FILES['xmls'])) {
                http_response_code(400);
                throw new Exception('No se han subido archivos');
            }

            $resultados = [];
            $archivos = $_FILES['xmls'];

            foreach ($archivos['tmp_name'] as $key => $tmpName) {

                $xmlContent = file_get_contents($tmpName);
                $xml = simplexml_load_string($xmlContent);
                $nombreArchivo = $archivos['name'][$key];
                
                if (!$xml) {
                    throw new Exception("XML inválido: $nombreArchivo");
                }
                

                // Datos XML
                $nifEmisor = (string)$xml->Emisor->NIF;
                $numeroFactura = (string)$xml->NumeroFactura;
                $fechaEmision = (string)$xml->FechaEmision;
                $cuotaIva = (string)$xml->Totales->CuotaIVA;
                $total = (string)$xml->Totales->Total;

                $hashActualXml = (string)$xml->NoVerifactu->HashActual;
                $hashAnteriorXml = (string)$xml->NoVerifactu->HashAnterior;
                $cadenaActual = (string)$xml->NoVerifactu->Cadena;
                $cadenaAnterior = (int)$cadenaActual - 1;
                $versionSif = (string)$xml->NoVerifactu->VersionSIF;

                $cadenaParaVerificar = implode('|', [
                    $versionSif,
                    $nifEmisor,
                    $numeroFactura,
                    $fechaEmision,
                    number_format((float)$cuotaIva, 2, '.', ''),
                    number_format((float)$total, 2, '.', ''),
                    $cadenaAnterior,
                    $cadenaActual,
                    $hashAnteriorXml
                ]);

                $hashCalculado = hash('sha256', $cadenaParaVerificar);

                $esIntegro = ($hashCalculado === $hashActualXml);
                $esDeMiEmpresa = ($nifEmisor === $company['nif']);

                $existeEnBBDD = false;
                $coincideConBBDD = false;

                if ($esDeMiEmpresa) {

                    $sql = "
                        SELECT r.xml_content
                        FROM invoice_records r
                        INNER JOIN invoices i ON r.invoice_id = i.id
                        WHERE r.hash_actual = :hash
                        AND i.company_id = :company_id
                    ";

                    $stmtDb = $pdo->prepare($sql);

                    $stmtDb->execute([
                        'hash' => $hashActualXml,
                        'company_id' => $company['id']
                    ]);

                    $invoice = $stmtDb->fetch();

                    if ($invoice) {
                         $existeEnBBDD = true;

                        $xmlSubido = new DOMDocument();
                        $xmlSubido->loadXML($xmlContent);

                        $xmlDb = new DOMDocument();
                        $xmlDb->loadXML($invoice['xml_content']);

                        $coincideConBBDD = ($xmlSubido->C14N() === $xmlDb->C14N());
                    }
                }

                $resultados[] = [
                    'sif'=> $versionSif,
                    'archivo' => $nombreArchivo,
                    'numero' => $numeroFactura,
                    'integro' => $esIntegro,
                    'empresa_correcta' => $esDeMiEmpresa,
                    'registrado' => $existeEnBBDD,
                    'coincide_BBDD' => $coincideConBBDD
                ];
            }

            // 🔹 Log de uso del verificador
            crearLog(
                $pdo,
                $userId,
                'XML_VERIFICADOS',
                json_encode([
                    'archivos' => count($resultados)
                ])
            );

            echo json_encode([
                'logs_integrity' => $logsIntegrity['ok'],
                'resultados' => $resultados
            ]);

        } catch (Throwable $e) {

            crearLog(
                $pdo,
                $userId ?? null,
                'ERROR_XML_VERIFICAR',
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