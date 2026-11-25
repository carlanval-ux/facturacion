<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Generador de adeudos directos SEPA, ISO 20022 pain.008.001.02.
 * Autónomo (sin base de datos) para poder probarlo con ejemplos.
 *
 * Acreedor: nombre, aci, iban, bic (opcional).
 * Operación: endtoend, importe, mandato, fechafirma (Y-m-d), tipo (CORE|B2B), secuencia (FRST|RCUR|FNAL|OOFF),
 *            fechacobro (Y-m-d), deudor, iban, bic (opcional), concepto.
 */
final class SepaXml
{
    public const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';
    public const SECUENCIAS = ['FRST', 'RCUR', 'FNAL', 'OOFF'];
    public const TIPOS = ['CORE', 'B2B'];

    /** Días hábiles mínimos entre la presentación y el cobro (configurables por acreedor). */
    public static function plazoMinimo(string $tipo, string $secuencia, array $plazos = []): int
    {
        if ($tipo === 'B2B') {
            return (int)($plazos['b2b'] ?? 1);
        }
        return in_array($secuencia, ['FRST', 'OOFF'], true) ? (int)($plazos['frst'] ?? 5) : (int)($plazos['rcur'] ?? 1);
    }

    /** Valida acreedor y operaciones; devuelve la lista de errores (vacía si todo es correcto). */
    public static function validar(array $acreedor, array $ops, DateTimeImmutable $hoy, array $plazos = []): array
    {
        $e = [];
        if (SepaTexto::limpio($acreedor['nombre'] ?? '', 70) === '') {
            $e[] = 'creditor-name-missing';
        }
        if (!self::aciValido($acreedor['aci'] ?? '')) {
            $e[] = 'creditor-id-invalid';
        }
        if (!Iban::esValido($acreedor['iban'] ?? '')) {
            $e[] = 'creditor-iban-invalid';
        }
        if (!empty($acreedor['bic']) && !Iban::bicValido($acreedor['bic'])) {
            $e[] = 'creditor-bic-invalid';
        }
        if (empty($ops)) {
            $e[] = 'sepa-no-operations';
        }

        $ids = [];
        foreach ($ops as $i => $o) {
            $ref = (string)($o['endtoend'] ?? ('#' . $i));
            $idLimpio = SepaTexto::id($ref);
            if ($idLimpio === '' || isset($ids[$idLimpio])) {
                $e[] = 'duplicated-or-empty-reference: ' . $ref;
            }
            $ids[$idLimpio] = true;

            if (!is_numeric($o['importe'] ?? null) || round((float)$o['importe'], 2) < 0.01 || round((float)$o['importe'], 2) > 999999999.99) {
                $e[] = 'invalid-amount: ' . $ref;
            }
            if (SepaTexto::id($o['mandato'] ?? '') === '') {
                $e[] = 'mandate-reference-missing: ' . $ref;
            }
            if (!self::fechaValida($o['fechafirma'] ?? '')) {
                $e[] = 'mandate-date-invalid: ' . $ref;
            }
            if (!Iban::esValido($o['iban'] ?? '')) {
                $e[] = 'debtor-iban-invalid: ' . $ref;
            }
            if (!empty($o['bic']) && !Iban::bicValido($o['bic'])) {
                $e[] = 'debtor-bic-invalid: ' . $ref;
            }
            if (SepaTexto::limpio($o['deudor'] ?? '', 70) === '') {
                $e[] = 'debtor-name-missing: ' . $ref;
            }
            if (!in_array($o['tipo'] ?? '', self::TIPOS, true) || !in_array($o['secuencia'] ?? '', self::SECUENCIAS, true)) {
                $e[] = 'invalid-scheme-or-sequence: ' . $ref;
                continue;
            }
            if (!self::fechaValida($o['fechacobro'] ?? '')) {
                $e[] = 'collection-date-invalid: ' . $ref;
                continue;
            }
            $cobro = new DateTimeImmutable($o['fechacobro']);
            if (!DiasHabiles::esHabil($cobro)) {
                $e[] = 'collection-date-not-business-day: ' . $ref;
            }
            $limite = DiasHabiles::sumar($hoy, self::plazoMinimo($o['tipo'], $o['secuencia'], $plazos));
            if ($cobro < $limite) {
                $e[] = 'collection-date-too-early: ' . $ref . ' (' . $limite->format('Y-m-d') . ')';
            }
        }
        return $e;
    }

    public static function generar(array $acreedor, array $ops, string $msgId, DateTimeImmutable $ahora, array $plazos = []): string
    {
        $errores = self::validar($acreedor, $ops, $ahora->setTime(0, 0), $plazos);
        if ($errores) {
            throw new InvalidArgumentException(implode(' | ', $errores));
        }

        // un bloque PmtInf por fecha de cobro + esquema + secuencia
        $grupos = [];
        foreach ($ops as $o) {
            $grupos[$o['fechacobro'] . '|' . $o['tipo'] . '|' . $o['secuencia']][] = $o;
        }
        ksort($grupos);

        $total = 0;
        foreach ($ops as $o) {
            $total += (int)round((float)$o['importe'] * 100);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $doc = $dom->createElementNS(self::NS, 'Document');
        $dom->appendChild($doc);
        $raiz = self::el($dom, $doc, 'CstmrDrctDbtInitn');

        $nombre = SepaTexto::limpio($acreedor['nombre'], 70);
        $msg = SepaTexto::id($msgId);
        $gh = self::el($dom, $raiz, 'GrpHdr');
        self::el($dom, $gh, 'MsgId', $msg);
        self::el($dom, $gh, 'CreDtTm', $ahora->format('Y-m-d\TH:i:s'));
        self::el($dom, $gh, 'NbOfTxs', (string)count($ops));
        self::el($dom, $gh, 'CtrlSum', self::cent($total));
        self::el($dom, self::el($dom, $gh, 'InitgPty'), 'Nm', $nombre);

        $n = 0;
        foreach ($grupos as $clave => $lista) {
            [$fecha, $tipo, $sec] = explode('|', $clave);
            $n++;
            $subtotal = 0;
            foreach ($lista as $o) {
                $subtotal += (int)round((float)$o['importe'] * 100);
            }

            $pi = self::el($dom, $raiz, 'PmtInf');
            self::el($dom, $pi, 'PmtInfId', SepaTexto::id($msg . '-' . $n));
            self::el($dom, $pi, 'PmtMtd', 'DD');
            self::el($dom, $pi, 'BtchBookg', 'true');
            self::el($dom, $pi, 'NbOfTxs', (string)count($lista));
            self::el($dom, $pi, 'CtrlSum', self::cent($subtotal));
            $tp = self::el($dom, $pi, 'PmtTpInf');
            self::el($dom, self::el($dom, $tp, 'SvcLvl'), 'Cd', 'SEPA');
            self::el($dom, self::el($dom, $tp, 'LclInstrm'), 'Cd', $tipo);
            self::el($dom, $tp, 'SeqTp', $sec);
            self::el($dom, $pi, 'ReqdColltnDt', $fecha);
            self::el($dom, self::el($dom, $pi, 'Cdtr'), 'Nm', $nombre);
            self::el($dom, self::el($dom, self::el($dom, $pi, 'CdtrAcct'), 'Id'), 'IBAN', Iban::normalizar($acreedor['iban']));
            self::agente($dom, $pi, 'CdtrAgt', (string)($acreedor['bic'] ?? ''));
            self::el($dom, $pi, 'ChrgBr', 'SLEV');
            $cs = self::el($dom, $pi, 'CdtrSchmeId');
            $otro = self::el($dom, self::el($dom, self::el($dom, $cs, 'Id'), 'PrvtId'), 'Othr');
            self::el($dom, $otro, 'Id', strtoupper(trim($acreedor['aci'])));
            self::el($dom, self::el($dom, $otro, 'SchmeNm'), 'Prtry', 'SEPA');

            foreach ($lista as $o) {
                $tx = self::el($dom, $pi, 'DrctDbtTxInf');
                self::el($dom, self::el($dom, $tx, 'PmtId'), 'EndToEndId', SepaTexto::id($o['endtoend']));
                $im = self::el($dom, $tx, 'InstdAmt', self::cent((int)round((float)$o['importe'] * 100)));
                $im->setAttribute('Ccy', 'EUR');
                $mr = self::el($dom, self::el($dom, $tx, 'DrctDbtTx'), 'MndtRltdInf');
                self::el($dom, $mr, 'MndtId', SepaTexto::id($o['mandato']));
                self::el($dom, $mr, 'DtOfSgntr', $o['fechafirma']);
                self::agente($dom, $tx, 'DbtrAgt', (string)($o['bic'] ?? ''));
                self::el($dom, self::el($dom, $tx, 'Dbtr'), 'Nm', SepaTexto::limpio($o['deudor'], 70));
                self::el($dom, self::el($dom, self::el($dom, $tx, 'DbtrAcct'), 'Id'), 'IBAN', Iban::normalizar($o['iban']));
                $concepto = SepaTexto::limpio($o['concepto'] ?? '', 140);
                if ($concepto !== '') {
                    self::el($dom, self::el($dom, $tx, 'RmtInf'), 'Ustrd', $concepto);
                }
            }
        }
        return $dom->saveXML();
    }

    /**
     * Comprobación del XML generado: bien formado, totales, importes, orden de elementos de cada PmtInf.
     * Si existe Data/pain.008.001.02.xsd (esquema oficial ISO 20022) valida además contra él.
     */
    public static function validarXml(string $xml, ?string $xsd = null): array
    {
        $e = [];
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return ['xml-not-well-formed'];
        }
        $xsd = $xsd ?? dirname(__DIR__) . '/Data/pain.008.001.02.xsd';
        if (is_file($xsd) && !$dom->schemaValidate($xsd)) {
            foreach (libxml_get_errors() as $le) {
                $e[] = 'xsd: ' . trim($le->message) . ' (l.' . $le->line . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $x = new DOMXPath($dom);
        $x->registerNamespace('s', self::NS);
        if ($x->query('/s:Document/s:CstmrDrctDbtInitn/s:GrpHdr')->length !== 1) {
            return array_merge($e, ['missing-group-header']);
        }
        $tx = $x->query('//s:DrctDbtTxInf')->length;
        if ((int)$x->query('//s:GrpHdr/s:NbOfTxs')->item(0)->textContent !== $tx) {
            $e[] = 'nboftxs-mismatch';
        }
        $suma = 0;
        foreach ($x->query('//s:DrctDbtTxInf/s:InstdAmt') as $a) {
            if (!preg_match('/^\d+\.\d{2}$/', $a->textContent)) {
                $e[] = 'amount-format';
            }
            $suma += (int)round((float)$a->textContent * 100);
        }
        if (self::cent($suma) !== $x->query('//s:GrpHdr/s:CtrlSum')->item(0)->textContent) {
            $e[] = 'ctrlsum-mismatch';
        }
        $orden = ['PmtInfId', 'PmtMtd', 'BtchBookg', 'NbOfTxs', 'CtrlSum', 'PmtTpInf', 'ReqdColltnDt', 'Cdtr', 'CdtrAcct', 'CdtrAgt', 'ChrgBr', 'CdtrSchmeId', 'DrctDbtTxInf'];
        foreach ($x->query('//s:PmtInf') as $pi) {
            $vistos = [];
            foreach ($pi->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $vistos[] = $c->localName;
                }
            }
            if (array_values(array_unique($vistos)) !== $orden) {
                $e[] = 'pmtinf-order';
            }
        }
        return $e;
    }

    /** Identificador del acreedor SEPA: país + 2 dígitos + 3 de negocio + identificador nacional (hasta 35). */
    public static function aciValido(?string $aci): bool
    {
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', strtoupper(trim((string)$aci))) === 1;
    }

    /** Dígito de control del ACI (solo aviso, no bloquea): mod 97 sobre identificador nacional + país + 00. */
    public static function aciControlOk(string $aci): bool
    {
        $aci = strtoupper(trim($aci));
        if (!self::aciValido($aci)) {
            return false;
        }
        $num = '';
        foreach (str_split(substr($aci, 7) . substr($aci, 0, 2) . '00') as $c) {
            $num .= ctype_digit($c) ? $c : (string)(ord($c) - 55);
        }
        $r = 0;
        foreach (str_split($num) as $d) {
            $r = ($r * 10 + (int)$d) % 97;
        }
        return (98 - $r) === (int)substr($aci, 2, 2);
    }

    private static function fechaValida(string $f): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
    }

    private static function cent(int $c): string
    {
        return sprintf('%d.%02d', intdiv($c, 100), $c % 100);
    }

    private static function agente(DOMDocument $dom, DOMElement $padre, string $nombre, string $bic): void
    {
        $fi = self::el($dom, self::el($dom, $padre, $nombre), 'FinInstnId');
        if ($bic !== '') {
            self::el($dom, $fi, 'BIC', strtoupper(trim($bic)));
        } else {
            self::el($dom, self::el($dom, $fi, 'Othr'), 'Id', 'NOTPROVIDED');
        }
    }

    private static function el(DOMDocument $dom, DOMElement $padre, string $nombre, ?string $valor = null): DOMElement
    {
        $n = $dom->createElementNS(self::NS, $nombre);
        if ($valor !== null) {
            $n->appendChild($dom->createTextNode($valor));
        }
        $padre->appendChild($n);
        return $n;
    }
}
