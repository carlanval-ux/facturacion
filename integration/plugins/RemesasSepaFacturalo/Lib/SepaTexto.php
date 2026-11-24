<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

/** Juego de caracteres SEPA: a-z A-Z 0-9 / - ? : ( ) . , ' + espacio. */
final class SepaTexto
{
    private const MAPA = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ø' => 'o', 'å' => 'a',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'Ñ' => 'N', 'Ç' => 'C', 'Ø' => 'O', 'Å' => 'A',
        '&' => 'y', '€' => 'EUR', '@' => '(at)', '_' => '-', '*' => '.', ';' => ',', '"' => "'", '’' => "'", '‘' => "'",
        '–' => '-', '—' => '-', 'º' => '.', 'ª' => '.',
    ];

    /** Texto apto para SEPA: transliterado, sin caracteres no permitidos, espacios compactados y truncado. */
    public static function limpio(?string $texto, int $max): string
    {
        $t = strtr((string)$texto, self::MAPA);
        $t = preg_replace('/[^a-zA-Z0-9\/\-?:().,\'+ ]/u', ' ', $t);
        $t = trim(preg_replace('/\s+/', ' ', $t));
        return substr($t, 0, $max);
    }

    /** Identificador (MsgId, EndToEndId…): sin espacios y sin barras al inicio/fin. */
    public static function id(?string $texto, int $max = 35): string
    {
        $t = str_replace(' ', '', self::limpio($texto, 200));
        return substr(trim($t, '/'), 0, $max);
    }

    public static function esValido(?string $texto, int $max): bool
    {
        $t = (string)$texto;
        return $t !== '' && strlen($t) <= $max && preg_match('/^[a-zA-Z0-9\/\-?:().,\'+ ]+$/', $t) === 1;
    }
}
