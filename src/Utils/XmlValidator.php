<?php

class XmlValidator {

    public static function validate(string $xml, string $xsdPath): array {

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $isValid = $dom->schemaValidate($xsdPath);
        $errors = libxml_get_errors();

        libxml_clear_errors();

        return [
            'valid' => $isValid,
            'errors' => array_map(
                fn($e) => trim($e->message),
                $errors
            )
        ];
    }
}
