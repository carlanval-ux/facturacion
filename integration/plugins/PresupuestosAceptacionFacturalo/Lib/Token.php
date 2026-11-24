<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

/** Token público del enlace: 256 bits aleatorios; en base de datos solo se guarda su SHA-256. */
final class Token
{
    public static function generar(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function valido(string $token): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $token);
    }
}
