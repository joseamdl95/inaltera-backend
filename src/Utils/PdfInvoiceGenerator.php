<?php

require_once __DIR__.'/lib/fpdf/fpdf.php';
require_once __DIR__.'/lib/phpqrcode/qrlib.php';

class PdfInvoiceGenerator {
    private static function txt($text) {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    }


    public static function generate(array $invoice, array $company, array $record, string $qrPayload): string {

        $tmpQr = sys_get_temp_dir() . '/qr_' . uniqid() . '.png';

        QRcode::png($qrPayload, $tmpQr, QR_ECLEVEL_M, 5);

        $sif = $record['sif'] ?? null;

        $pdf = new FPDF();
        $pdf->AddPage();

        // 🔥 LOGO EMPRESA
        if (!empty($company['logo_url'])) {
            try {

                $tmpLogo = sys_get_temp_dir() . '/logo_' . uniqid() . '.png';

                // Descargar logo desde R2
                file_put_contents($tmpLogo, file_get_contents($company['logo_url']));

                // Insertar en PDF
                $pdf->Image($tmpLogo, 10, 10, 40); // x, y, width

                // limpiar
                unlink($tmpLogo);

            } catch (Exception $e) {
                // si falla, no rompe el PDF
            }
        }

        $pdf->Ln($company['logo_url'] ? 35 : 10);

        // Empresa
        $pdf->SetFont('Arial','B',14);
        $pdf->Cell(0,10,self::txt($company['razon_social']),0,1);
        

        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0,6,self::txt('Dirección: '.$company['direccion']),0,1);
        $pdf->Cell(0,6,self::txt('NIF: '.$company['nif']),0,1);
        if ($sif) {$pdf->Cell(0,6,self::txt('Sistema: '.$sif['software_nombre'].' v'.$sif['version']),0,1);}

        $pdf->Ln(5);

        // Factura
        $pdf->SetFont('Arial','B',14);
        $pdf->Cell(0,8,self::txt('Factura '.$invoice['numero']),0,1);
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0,6,self::txt('Fecha: '.$invoice['fecha_emision']),0,1);

        $pdf->Ln(5);
        
        $tipoFactura = $invoice['tipo_factura'] ?? 'F1';

        if ($tipoFactura !== 'F1') {

            $pdf->SetFont('Arial','B',12);
            $pdf->Ln(5);

            $titulo = $tipoFactura === 'R1'
                ? 'FACTURA RECTIFICATIVA (SUSTITUTIVA)'
                : 'FACTURA RECTIFICATIVA (POR DIFERENCIA)';

            $pdf->Cell(0,10,$titulo,0,1,'C');

            $pdf->Ln(5);

            $pdf->SetFont('Arial','',10);

            $pdf->MultiCell(0,6, self::txt(
                "Factura rectificada: " . ($invoice['factura_rectificada_id'] ?? '-') . "\n" .
                "Motivo: " . ($invoice['motivo_rectificacion'] ?? '-')
            ));

        }

        $pdf->Ln(5);

        // Cliente
        $pdf->Cell(0,6,self::txt('Cliente: '.$invoice['cliente_nombre']),0,1);
        $pdf->Cell(0,6,self::txt('NIF Cliente: '.$invoice['cliente_nif']),0,1);
        $pdf->Cell(0,6,self::txt('Dirección: '.$invoice['cliente_direccion']),0,1);

        $pdf->Ln(5);

        //Lineas
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(60,7,self::txt('Descripcion'),1);
        $pdf->Cell(15,7,self::txt('Cant.'),1,0,'R');
        $pdf->Cell(20,7,self::txt('Precio'),1,0,'R');
        $pdf->Cell(20,7,self::txt('Base'),1,0,'R');
        $pdf->Cell(15,7,self::txt('IVA%'),1,0,'R');
        $pdf->Cell(20,7,self::txt('IVA €'),1,0,'R');
        $pdf->Cell(15,7,self::txt('IRPF%'),1,0,'R');
        $pdf->Cell(20,7,self::txt('IRPF €'),1,1,'R');

        $pdf->SetFont('Arial','',9);

        foreach ($invoice['lines'] as $line) {

            $pdf->Cell(60,6,self::txt($line['descripcion']),1);
            $pdf->Cell(15,6,self::txt(number_format($line['cantidad'],2)),'1',0,'R');
            $pdf->Cell(20,6,self::txt(number_format($line['precio_unitario'],2).' €'),1,0,'R');
            $pdf->Cell(20,6,self::txt(number_format($line['base_imponible'],2).' €'),1,0,'R');

            // IVA
            if ((float)$line['iva_tipo'] > 0) {
                $pdf->Cell(15,6,self::txt(number_format($line['iva_tipo'],2).'%'),1,0,'R');
                $pdf->Cell(20,6,self::txt(number_format($line['iva_cuota'],2).' €'),1,0,'R');
            } else {
                $pdf->Cell(15,6,'-',1,0,'C');
                $pdf->Cell(20,6,'-',1,0,'C');
            }

            // IRPF (opcional)
            if ((float)$line['irpf_porcentaje'] > 0) {
                $pdf->Cell(15,6,self::txt(number_format($line['irpf_porcentaje'],2).'%'),1,0,'R');
                $pdf->Cell(20,6,self::txt(number_format($line['cuota_irpf'],2).' €'),1,1,'R');
            } else {
                $pdf->Cell(15,6,'-',1,0,'C');
                $pdf->Cell(20,6,'-',1,1,'C');
            }
        }

        $pdf->Ln(5);
        
        // Totales
        $pdf->SetFont('Arial','B',10);

        $pdf->Cell(150,7,self::txt('Base imponible'),0,0,'R');
        $pdf->Cell(40,7,self::txt(number_format($invoice['base_imponible'],2).' €'),0,1,'R');

        if ((float)$invoice['cuota_iva'] > 0) {
                $pdf->Cell(150,7,self::txt('IVA total'),0,0,'R');
                $pdf->Cell(40,7,self::txt(number_format($invoice['cuota_iva'],2).' €'),0,1,'R');
        }

        if ((float)$invoice['cuota_irpf'] > 0) {
            $pdf->Cell(150,7,self::txt('IRPF total'),0,0,'R');
            $pdf->Cell(40,7,self::txt('- '.number_format($invoice['cuota_irpf'],2).' €'),0,1,'R');
        }

        $pdf->SetFont('Arial','B',12);
        $pdf->Cell(150,9,self::txt('TOTAL FACTURA'),0,0,'R');
        $pdf->Cell(40,9,self::txt(number_format($invoice['total'],2).' €'),0,1,'R');

        $pdf->Ln(10);

        // Hash
        $pdf->SetFont('Arial','',8);
        $pdf->MultiCell(0,5,self::txt('Hash: '.$record['hash_actual']));

        // QR
        $pdf->Image($tmpQr, 150, 50, 40);

        unlink($tmpQr);

        //texto legal
        $pdf->Ln(5);
        $pdf->SetFont('Arial','',7);
        $pdf->MultiCell(0,4,self::txt('Factura verificable en la sede electónica de la AEAT.'));


        $outputDir = __DIR__.'/../../storage/facturas_emitidas';
        if (!is_dir($outputDir)) mkdir($outputDir,0777,true);

        $file = $outputDir . '/factura_' . $company['nif'] . '_' . $invoice['numero'] . '.pdf';

        $pdf->Output('F', $file);

        return $file;
    }
}
