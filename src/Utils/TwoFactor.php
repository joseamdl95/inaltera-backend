<?php

class TwoFactor {
    // Genera una clave secreta compatible con Google Authenticator (Base32)
    public static function generateSecret(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[rand(0, 31)];
        }
        return $secret;
    }

    // Verifica si el código de 6 dígitos es válido
    public static function verifyCode(string $secret, string $code): bool {
        $timeWindow = floor(time() / 30);
        // Comprobamos la ventana actual, la anterior y la siguiente
        for ($i = -1; $i <= 1; $i++) {
            // ⚠️ Cambiado self:: por static:: para ayudar al editor
            if (static::calculateCode($secret, $timeWindow + $i) == $code) {
                return true;
            }
        }
        return false;
    }

    private static function calculateCode(string $secret, int $timeSlice): string {
        $key = static::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $hashPart = substr($hash, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $base32): string {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        $output = ''; $v = 0; $vBits = 0;
        foreach (str_split($base32) as $char) {
            if (!isset($base32charsFlipped[$char])) continue;
            $v = ($v << 5) | $base32charsFlipped[$char];
            $vBits += 5;
            if ($vBits >= 8) {
                $vBits -= 8;
                $output .= chr(($v >> $vBits) & 0xFF);
            }
        }
        return $output;
    }
}