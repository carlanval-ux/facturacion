<?php
/**
 * RecordatorioImpagosFacturalo - Lógica pura (sin base de datos) de los pasos de reclamación.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib;

use DateTimeImmutable;

class Pasos
{
    /** Pasos en orden de tono creciente. */
    const PASOS = ['previo', 'dia', 'post1', 'post2', 'post3'];

    /** Desplazamiento en días respecto al vencimiento: negativo antes, 0 el día, positivo después. */
    public static function desplazamiento(string $paso, int $dias): int
    {
        $dias = max(0, $dias);
        if ($paso === 'previo') {
            return -$dias;
        }

        return $paso === 'dia' ? 0 : $dias;
    }

    /**
     * Decide qué paso enviar a un recibo.
     *
     * @param array $offsets paso => desplazamiento, solo de los pasos activos
     * @param array $hechos pasos ya resueltos (enviado, omitido, sin email, errores agotados)
     * @param string $vencimiento Y-m-d
     * @param string $hoy Y-m-d
     * @return array ['enviar' => ?string, 'omitir' => string[]]
     *   Solo se envía el último paso vencido: si el módulo se activa tarde no se
     *   disparan varios a la vez; los anteriores quedan 'omitidos'.
     */
    public static function decidir(array $offsets, array $hechos, string $vencimiento, string $hoy): array
    {
        $diff = self::diasEntre($vencimiento, $hoy);
        $vencidos = [];
        foreach ($offsets as $paso => $offset) {
            if ($diff >= $offset) {
                $vencidos[$paso] = $offset;
            }
        }
        if (empty($vencidos)) {
            return ['enviar' => null, 'omitir' => []];
        }

        asort($vencidos); // el último (mayor desplazamiento) es el que toca
        $ultimo = array_key_last($vencidos);
        $omitir = [];
        foreach (array_keys($vencidos) as $paso) {
            if ($paso !== $ultimo && false === in_array($paso, $hechos, true)) {
                $omitir[] = $paso;
            }
        }

        return [
            'enviar' => in_array($ultimo, $hechos, true) ? null : $ultimo,
            'omitir' => $omitir,
        ];
    }

    /** Paso que corresponde hoy (el último vencido; si ninguno, el primero) para un envío manual. */
    public static function pasoActual(array $offsets, string $vencimiento, string $hoy): string
    {
        asort($offsets);
        $diff = self::diasEntre($vencimiento, $hoy);
        $elegido = (string)array_key_first($offsets);
        foreach ($offsets as $paso => $offset) {
            if ($diff >= $offset) {
                $elegido = (string)$paso;
            }
        }
        return $elegido;
    }

    /** Días de $b respecto a $a (positivo si $b es posterior). */
    public static function diasEntre(string $a, string $b): int
    {
        $da = new DateTimeImmutable($a);
        $db = new DateTimeImmutable($b);
        return (int)$da->diff($db)->format('%r%a');
    }

    /** Hora entera en [ini, fin) y, opcionalmente, solo de lunes a viernes ($diaSemana 1=lunes..7). */
    public static function enHorario(int $hora, int $diaSemana, int $ini, int $fin, bool $soloLaborables): bool
    {
        if ($soloLaborables && $diaSemana > 5) {
            return false;
        }

        return $ini <= $fin ? ($hora >= $ini && $hora < $fin) : ($hora >= $ini || $hora < $fin);
    }

    /**
     * Sustituye {variables}. Con $html se escapan texto y valores
     * (el cuerpo se guarda como texto plano y se envía como HTML).
     */
    public static function render(string $texto, array $vars, bool $html): string
    {
        if ($html) {
            $texto = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        }

        $pares = [];
        foreach ($vars as $clave => $valor) {
            $valor = (string)$valor;
            $pares['{' . $clave . '}'] = $html ? htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') : $valor;
        }

        $texto = strtr($texto, $pares);
        return $html ? nl2br($texto) : $texto;
    }

    /** Token de baja: firma del código de cliente con el secreto del tenant. */
    public static function token(string $codcliente, string $secreto): string
    {
        return substr(hash_hmac('sha256', $codcliente, $secreto), 0, 40);
    }

    public static function tokenValido(string $codcliente, string $token, string $secreto): bool
    {
        return '' !== $secreto && '' !== $codcliente && hash_equals(self::token($codcliente, $secreto), $token);
    }

    /** Lista separada por comas, espacios o saltos de línea → array en mayúsculas, sin vacíos ni repetidos. */
    public static function lista(string $texto): array
    {
        $partes = preg_split('/[\s,;]+/', mb_strtoupper($texto), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($partes ?: []));
    }
}
