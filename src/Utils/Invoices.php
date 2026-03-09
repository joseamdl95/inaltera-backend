<?php

function verificarIntegridadFacturas(PDO $pdo, $companyId)
{
    $stmt = $pdo->prepare("
        SELECT 
            r.xml_content,
            r.cadena_hash,
            r.hash_actual,
            r.hash_anterior
        FROM invoice_records r
        JOIN invoices i ON r.invoice_id = i.id
        WHERE i.company_id = :company_id
        ORDER BY r.cadena_hash ASC
    ");

    $stmt->execute([
        'company_id' => $companyId
    ]);

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$records) {
        return [
            'ok' => true,
            'total' => 0
        ];
    }

    $hashAnteriorEsperado = str_repeat('0', 64);
    $cadenaEsperada = 1;

    foreach ($records as $r) {

        if ((int)$r['cadena_hash'] !== $cadenaEsperada) {
            return [
                'ok' => false,
                'cadena_hash' => $r['cadena_hash'],
                'error' => 'Salto en la cadena'
            ];
        }

        if ($r['hash_anterior'] !== $hashAnteriorEsperado) {
            return [
                'ok' => false,
                'cadena_hash' => $r['cadena_hash'],
                'error' => 'Hash anterior incorrecto'
            ];
        }

        $xml = simplexml_load_string($r['xml_content']);

        $versionSif = (string)$xml->NoVerifactu->VersionSIF;
        $nif = (string)$xml->Emisor->NIF;
        $numero = (string)$xml->NumeroFactura;
        $fecha = (string)$xml->FechaEmision;

        $cuotaIva = (string)$xml->Totales->CuotaIVA;
        $total = (string)$xml->Totales->Total;

        $cadenaAnterior = (int)$r['cadena_hash'] - 1;

        $cadena = implode('|', [
            $versionSif,
            $nif,
            $numero,
            $fecha,
            number_format((float)$cuotaIva, 2, '.', ''),
            number_format((float)$total, 2, '.', ''),
            $cadenaAnterior,
            $r['cadena_hash'],
            $hashAnteriorEsperado
        ]);

        $hashCalculado = hash('sha256', $cadena);

        if ($hashCalculado !== $r['hash_actual']) {
            return [
                'ok' => false,
                'cadena_hash' => $r['cadena_hash'],
                'error' => 'Hash inválido'
            ];
        }

        $hashAnteriorEsperado = $r['hash_actual'];
        $cadenaEsperada++;
    }

    return [
        'ok' => true,
        'total' => count($records)
    ];
}