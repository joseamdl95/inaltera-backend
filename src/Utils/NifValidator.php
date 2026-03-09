<?php

class NifValidator
{
    private static string $letrasDni = "TRWAGMYFPDXBNJZSQVHLCKE";

    // ---------- DNI ----------
    private static function validarDni(string $dni): bool
    {
        if (!preg_match('/^(\d{8})([A-Z])$/', $dni, $m)) {
            return false;
        }

        $numero = intval($m[1]);
        $letra = $m[2];

        return self::$letrasDni[$numero % 23] === $letra;
    }

    // ---------- NIE ----------
    private static function validarNie(string $nie): bool
    {
        if (!preg_match('/^([XYZ])(\d{7})([A-Z])$/', $nie, $m)) {
            return false;
        }

        $mapa = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $numero = intval($mapa[$m[1]] . $m[2]);
        $letra = $m[3];

        return self::$letrasDni[$numero % 23] === $letra;
    }

    // ---------- CIF ----------
    private static function validarCif(string $cif): bool{
        return (bool) preg_match('/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', strtoupper($cif));
    }

    // ---------- VALIDADOR GENERAL ----------
    public static function validar(string $nif): bool
    {
        $value = strtoupper(trim($nif));

        return
            self::validarDni($value) ||
            self::validarNie($value) ||
            self::validarCif($value);
    }
}
