<?php

class AIService {
    // Tu clave que ya vimos que funciona
     private static function getApiKey() {
        $key = getenv("GEMINI_API_KEY");

        if (!$key) {
            throw new Exception("API key de Gemini no configurada");
        }

        return $key;
    }
    // 🟢 CAMBIO: Usamos el modelo 2.0 que aparece en tu lista
    private static $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
    
    public static function extractInvoiceData($pdfPath) {
        $pdfBase64 = base64_encode(file_get_contents($pdfPath));

        $prompt = "Analiza esta factura y extrae los datos con precisión. 
        REGLAS:
        1. TIPO: 'F1' (Ordinaria). Si menciona 'Rectificativa', 'Abono' o 'Sustituye a', usa 'R1' (si los importes son el total corregido) o 'R2' (si es solo la diferencia).
        2. REFERENCIA: Si es R1 o R2, busca el número de factura que rectifica (ej. 'Rectifica a F-123') y el motivo (ej. 'Error en precio').
        3. NÚMERO DE FACTURA: Es vital. Busca términos como 'Número', 'Factura nº', 'Invoice No'.
        4. LÍNEAS: Extrae cada concepto. Si el IVA/IRPF solo aparecen en el total, cálculalos proporcionalmente para cada línea.
        5. IVA: Solo valores permitidos (0, 4, 10, 21).
        6. RECEPTOR: Extrae NIF, Nombre, Dirección y País (ES por defecto).
        7. DIRECCIÓN: Devuelve SIEMPRE la dirección en este formato exacto:
            'Calle y número, CP Ciudad (Provincia)'

            Ejemplo correcto:
            'Calle Mayor 12, 28001 Madrid (Madrid)'

            Reglas adicionales:
            -calle y número coge toda la informacion que no sea cp , ciudad y Provincia eliminando las comas ','
            - El CP debe ser de 5 dígitos
            - La provincia debe ir SIEMPRE entre paréntesis
            - Si la provincia no aparece explícitamente, dedúcela a partir de la ciudad
            - No incluir el país en la dirección
            - No cambiar el orden ni el formato

        JSON:
        {
        \"tipo_factura\": \"F1\",
        \"factura_rectificada_num\": \"NUM-ORIGINAL\", // Solo si es R1/R2
        \"motivo_rectificacion\": \"Motivo extraído\",  // Solo si es R1/R2
        \"cliente\": {
            \"nif\": \"\", 
            \"nombre\": \"\", 
            \"direccion\": \"\", 
            \"pais\": \"ES\"
        },
        \"factura\": {
            \"numero\": \"\",
            \"fecha_emision\": \"YYYY-MM-DD\"
        },
        \"lineas\": [
            {
            \"concepto\": \"\",
            \"cantidad\": 1,
            \"precio_unitario\": 0.00,
            \"iva\": 21,
            \"irpf\": 0
            }
        ]
        }";

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt],
                        [
                            "inline_data" => [
                                "mime_type" => "application/pdf",
                                "data" => $pdfBase64
                            ]
                        ]
                    ]
                ]
            ],
            "generationConfig" => [
                "responseMimeType" => "application/json"
            ]
        ];

        $ch = curl_init(self::$apiUrl . "?key=" . self::getApiKey());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // 🟢 IMPORTANTE: Para evitar el error de certificado que vimos en tu consola
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new Exception("Error de conexión con la IA (Código $httpCode): " . $response);
        }

        $result = json_decode($response, true);
        $jsonContent = $result['candidates'][0]['content']['parts'][0]['text'];
        
        return json_decode(trim($jsonContent), true);
    }
}