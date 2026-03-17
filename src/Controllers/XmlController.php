<?php

require_once __DIR__ . '/../Utils/Auth.php';
require_once __DIR__ . '/../Utils/Logs.php';

class XmlController {

    public static function verificar(PDO $pdo) {

        $userId = null;

        try {

            if (!isset($_FILES['xmls'])) {
                http_response_code(400);
                throw new Exception('No se han subido archivos');
            }

            $resultados = [];
            $archivos = $_FILES['xmls'];

            foreach ($archivos['tmp_name'] as $key => $tmpName) {

                $xmlContent = file_get_contents($tmpName);
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom->loadXML($xmlContent);

                // 🔹 AJUSTA LA RUTA A TU XSD
                $xsdPath = __DIR__ . '/../../xsd/noverifactu_v1.xsd';

                $estructuraValida = $dom->schemaValidate($xsdPath);

                if (!$estructuraValida) {
                    $errores = libxml_get_errors();
                    libxml_clear_errors();
                }

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

                $hashCorrecto = ($hashCalculado === $hashActualXml);

                $stmtCompany = $pdo->prepare("
                    SELECT id 
                    FROM companies 
                    WHERE nif = :nif
                    LIMIT 1
                ");

                $stmtCompany->execute([
                    'nif' => $nifEmisor
                ]);

                $company = $stmtCompany->fetch();

                $companyId = $company ? $company['id'] : null;

                $cadenaGlobalOk = null;

                if ($companyId) {
                    $check = verificarIntegridadFacturas($pdo, $companyId);
                    $cadenaGlobalOk = $check['ok'];
                }
                

                $existeEnBBDD = false;
                $coincideConBBDD = false;

                    $sql = "
                        SELECT r.xml_content
                        FROM invoice_records r
                        WHERE r.hash_actual = :hash
                    ";

                    $stmtDb = $pdo->prepare($sql);

                    $stmtDb->execute([
                        'hash' => $hashActualXml
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
        

                $resultados[] = [
                    'sif'=> $versionSif,
                    'archivo' => $nombreArchivo,
                    'numero' => $numeroFactura,
                    'xml_valido' => $estructuraValida,
                    'hashCorrecto' => $hashCorrecto,
                    'cadena_integra' => $cadenaGlobalOk,
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