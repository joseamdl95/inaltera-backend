<?php

require_once __DIR__ . '/lib/fpdf/fpdf.php';
require_once __DIR__ . '/lib/phpqrcode/qrlib.php';
require_once __DIR__ . '/lib/fpdi/src/autoload.php';

use setasign\Fpdi\Fpdi;

class PdfInvoiceSealer {
    
    private static function txt($text) {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    }

    public static function sealOriginal(string $sourcePath, string $destPath, array $record, string $qrPayload): string {
        $pdf = new Fpdi();
        
        if (!file_exists($sourcePath)) {
            throw new Exception("Archivo original no encontrado en: " . $sourcePath);
        }

        $pageCount = $pdf->setSourceFile($sourcePath);

        // Generar QR temporal
        $tmpQr = sys_get_temp_dir() . '/qr_seal_' . uniqid() . '.png';
        QRcode::png($qrPayload, $tmpQr, QR_ECLEVEL_M, 5);

        for ($n = 1; $n <= $pageCount; $n++) {
            $tplIdx = $pdf->importPage($n);
            $specs = $pdf->getTemplateSize($tplIdx);

            $pdf->addPage($specs['orientation'], [$specs['width'], $specs['height']]);
            $pdf->useTemplate($tplIdx);

            if ($n === $pageCount) {
                // --- CRUCIAL: Desactivar salto de página automático ---
                $pdf->SetAutoPageBreak(false); 

                $w = $specs['width'];
                $h = $specs['height'];
                $footerHeight = 30; // Aumentamos un poco el margen del pie

                // 1. Área blanca en el pie (para tapar lo que haya debajo)
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Rect(0, $h - $footerHeight, $w, $footerHeight, 'F');
                
                // 2. Insertar QR (Esquina derecha)
                $pdf->Image($tmpQr, $w - 35, $h - 26, 22, 22);
                
                // 3. Texto legal
                $pdf->SetTextColor(50, 50, 50);
                
                // Hash Actual
                $pdf->SetFont('Arial', 'B', 7);
                $pdf->SetXY(10, $h - 26);
                $pdf->Cell(0, 4, self::txt("HUELA DIGITAL (SISTEMA VERI*FACTU):"), 0, 1);
                
                $pdf->SetFont('Arial', '', 6);
                $pdf->SetX(10);
                $pdf->MultiCell($w - 50, 3, strtoupper($record['hash_actual']), 0, 'L');
                
                // Disclaimer 
                $pdf->SetFont('Arial', 'I', 7);
                $pdf->SetXY(10, $h - 10);
                $pdf->Cell(0, 5, self::txt("Factura verificable en la sede electónica de la AEAT."), 0, 0, 'L');

                // Volver a activar si hubiera más lógica después (opcional)
                $pdf->SetAutoPageBreak(true, 10);
            }
        }

        $pdf->Output('F', $destPath);
        
        if (file_exists($tmpQr)) unlink($tmpQr);

        return $destPath;
    }
}