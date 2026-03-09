<?php

class NoVerifactuXml {

    public static function generate(array $invoice, array $company, array $record): string {

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Factura/>');

        $xml->addChild('NumeroFactura', htmlspecialchars($invoice['numero']));
        $xml->addChild('FechaEmision', date('Y-m-d\TH:i:s', strtotime($invoice['fecha_emision'])));

        $emisor = $xml->addChild('Emisor');
        $emisor->addChild('RazonSocial', htmlspecialchars($company['razon_social']));
        $emisor->addChild('NIF', htmlspecialchars($company['nif']));
        $emisor->addChild('Pais', htmlspecialchars($company['pais']));

        $receptor = $xml->addChild('Receptor');
        $receptor->addChild('Nombre', htmlspecialchars($invoice['cliente_nombre']));
        $receptor->addChild('NIF', htmlspecialchars($invoice['cliente_nif']));
        $receptor->addChild('Pais', htmlspecialchars($invoice['cliente_pais'] ?? 'ES'));

        $lineas = $xml->addChild('Lineas');

        foreach ($invoice['lines'] as $line) {
            $l = $lineas->addChild('Linea');

            $l->addChild('Descripcion', htmlspecialchars($line['descripcion']));
            $l->addChild('Cantidad', number_format($line['cantidad'], 2, '.', ''));
            $l->addChild('PrecioUnitario', number_format($line['precio_unitario'], 2, '.', ''));
            $l->addChild('BaseImponible', number_format($line['base_imponible'], 2, '.', ''));

            $l->addChild('TipoIVA', number_format($line['iva_tipo'], 2, '.', ''));
            $l->addChild('CuotaIVA', number_format($line['iva_cuota'], 2, '.', ''));

            if ((float)$line['irpf_porcentaje'] > 0) {
                $l->addChild('TipoIRPF', number_format($line['irpf_porcentaje'], 2, '.', ''));
                $l->addChild('CuotaIRPF', number_format($line['cuota_irpf'], 2, '.', ''));
            }
        }

        $totales = $xml->addChild('Totales');
        $totales->addChild('BaseImponible', number_format($invoice['base_imponible'], 2, '.', ''));
        $totales->addChild('CuotaIVA', number_format($invoice['cuota_iva'], 2, '.', ''));
        $totales->addChild('CuotaIRPF', number_format($invoice['cuota_irpf'], 2, '.', ''));
        $totales->addChild('Total', number_format($invoice['total'], 2, '.', ''));

        $tipoFactura = $record['tipo_factura'] ?? 'F1';

        if ($tipoFactura !== 'F1') {

            $rect = $xml->addChild('Rectificacion');

            $rect->addChild('Tipo', $tipoFactura);

            if (!empty($record['numero_rectificada'])) {
                $rect->addChild(
                    'FacturaRectificada',
                    htmlspecialchars($record['numero_rectificada'])
                );
            }

            if (!empty($record['motivo_rectificacion'])) {
                $rect->addChild(
                    'Motivo',
                    htmlspecialchars($record['motivo_rectificacion'])
                );
            }   
        }

        
        $nvf = $xml->addChild('NoVerifactu');
        $nvf->addChild('HashActual', $record['hash_actual']);
        $nvf->addChild('HashAnterior', $record['hash_anterior'] ?? '');
        $nvf->addChild('Cadena', $record['cadena_hash']);
        $versionSif = '';

        if (!empty($record['sif'])) {
            $versionSif = $record['sif']['software_nombre'] . '-' . $record['sif']['version'];
        }

        $nvf->addChild('VersionSIF', htmlspecialchars($versionSif));

        return $xml->asXML();
    }
}
