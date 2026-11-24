<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

/** Validación y presentación de IBAN (ISO 13616, módulo 97). Sin dependencias. */
final class Iban
{
    /** Longitud total por país (zona SEPA y adyacentes). */
    private const LONGITUDES = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22,
        'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24,
        'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'VA' => 22,
    ];

    public static function normalizar(?string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$iban));
    }

    public static function esValido(?string $iban): bool
    {
        $iban = self::normalizar($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }
        $pais = substr($iban, 0, 2);
        if (!isset(self::LONGITUDES[$pais]) || strlen($iban) !== self::LONGITUDES[$pais]) {
            return false;
        }

        // se mueven los 4 primeros caracteres al final y se convierten letras en números (A=10…)
        $mover = substr($iban, 4) . substr($iban, 0, 4);
        $resto = 0;
        foreach (str_split($mover) as $c) {
            $n = ctype_digit($c) ? $c : (string)(ord($c) - 55);
            foreach (str_split($n) as $d) {
                $resto = ($resto * 10 + (int)$d) % 97;
            }
        }
        return $resto === 1;
    }

    /** IBAN en grupos de 4; con $enmascarar solo se ven los 4 primeros y los 4 últimos caracteres. */
    public static function formato(?string $iban, bool $enmascarar = true): string
    {
        $iban = self::normalizar($iban);
        if ($iban === '') {
            return '';
        }
        if ($enmascarar && strlen($iban) > 8) {
            $iban = substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
        }
        return trim(chunk_split($iban, 4, ' '));
    }

    public static function bicValido(?string $bic): bool
    {
        return (bool)preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim((string)$bic)));
    }
}
