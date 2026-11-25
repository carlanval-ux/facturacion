<?php
/**
 * VerifactuFacturalo - Construcción de los registros de facturación (alta/anulación), del sobre SOAP y lectura de la respuesta.
 * Esquemas públicos de la AEAT: SuministroInformacion.xsd, SuministroLR.xsd, RespuestaSuministro.xsd. Licencia LGPL-3.0-or-later.
 * Sin dependencias de FacturaScripts. Los importes se pasan como float y se formatean aquí; las fechas como Y-m-d.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use RuntimeException;

class Xml
{
    const NS_SF = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
    const NS_LR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
    const MAX_REGISTROS = 1000;

    public static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function importe(float $v): string
    {
        $s = number_format(round($v, 2), 2, '.', '');
        return $s === '-0.00' ? '0.00' : $s;
    }

    /** Y-m-d → DD-MM-AAAA */
    public static function fecha(string $ymd): string
    {
        return (new DateTimeImmutable($ymd))->format('d-m-Y');
    }

    /** ISO 8601 con huso de Madrid: 2026-11-19T12:00:00+01:00 */
    public static function fechaHora(DateTimeImmutable $momento): string
    {
        return $momento->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d\TH:i:sP');
    }

    /**
     * $d: emisor{nif,nombre}, sistema{nombre,nif,id,version,instalacion}, factura{numserie,fecha(Y-m-d),tipo,descripcion,
     * desglose[{iva,base,cuota,recargo,cuota_recargo}], destinatario?{nombre,nif}, rectificada?{numserie,fecha}, subsanacion?},
     * anterior?{nif,numserie,fecha(Y-m-d),huella}, momento DateTimeImmutable.
     * @return array{xml:string,huella:string,fecha_hora:string,cuota_total:string,importe_total:string}
     */
    public static function alta(array $d): array
    {
        $f = $d['factura'];
        $fecha = self::fecha($f['fecha']);
        $fechaHora = self::fechaHora($d['momento']);
        $cuota = 0.0;
        $total = 0.0;
        $detalle = '';
        foreach ($f['desglose'] as $l) {
            $cr = round($l['cuota_recargo'] ?? 0, 2);
            $cuota += round($l['cuota'], 2) + $cr;
            $total += round($l['base'], 2) + round($l['cuota'], 2) + $cr;
            $detalle .= self::detalle($l);
        }
        $cuotaTotal = self::importe($cuota);
        $importeTotal = self::importe($total);
        $anterior = $d['anterior'] ?? null;
        $huella = Huella::alta($d['emisor']['nif'], $f['numserie'], $fecha, $f['tipo'], $cuotaTotal, $importeTotal, $anterior['huella'] ?? '', $fechaHora);

        $x = '<sf:RegistroAlta><sf:IDVersion>1.0</sf:IDVersion><sf:IDFactura><sf:IDEmisorFactura>' . self::esc($d['emisor']['nif']) . '</sf:IDEmisorFactura>'
            . '<sf:NumSerieFactura>' . self::esc($f['numserie']) . '</sf:NumSerieFactura><sf:FechaExpedicionFactura>' . $fecha . '</sf:FechaExpedicionFactura></sf:IDFactura>'
            . '<sf:NombreRazonEmisor>' . self::esc($d['emisor']['nombre']) . '</sf:NombreRazonEmisor>'
            . (!empty($f['subsanacion']) ? '<sf:Subsanacion>S</sf:Subsanacion><sf:RechazoPrevio>S</sf:RechazoPrevio>' : '')
            . '<sf:TipoFactura>' . $f['tipo'] . '</sf:TipoFactura>';
        if (!empty($f['rectificada'])) {
            // rectificativa «por diferencias» (I): las líneas de la rectificativa llevan los importes de la diferencia
            $x .= '<sf:TipoRectificativa>I</sf:TipoRectificativa><sf:FacturasRectificadas><sf:IDFacturaRectificada><sf:IDEmisorFactura>' . self::esc($d['emisor']['nif']) . '</sf:IDEmisorFactura>'
                . '<sf:NumSerieFactura>' . self::esc($f['rectificada']['numserie']) . '</sf:NumSerieFactura><sf:FechaExpedicionFactura>' . self::fecha($f['rectificada']['fecha']) . '</sf:FechaExpedicionFactura>'
                . '</sf:IDFacturaRectificada></sf:FacturasRectificadas>';
        }
        $x .= '<sf:DescripcionOperacion>' . self::esc(mb_substr($f['descripcion'] ?: 'Prestación de servicios', 0, 500)) . '</sf:DescripcionOperacion>';
        if (!empty($f['destinatario'])) {
            $x .= '<sf:Destinatarios><sf:IDDestinatario><sf:NombreRazon>' . self::esc($f['destinatario']['nombre']) . '</sf:NombreRazon><sf:NIF>' . self::esc($f['destinatario']['nif']) . '</sf:NIF></sf:IDDestinatario></sf:Destinatarios>';
        }
        $x .= '<sf:Desglose>' . $detalle . '</sf:Desglose><sf:CuotaTotal>' . $cuotaTotal . '</sf:CuotaTotal><sf:ImporteTotal>' . $importeTotal . '</sf:ImporteTotal>'
            . self::encadenamiento($anterior) . self::sistema($d['sistema'])
            . '<sf:FechaHoraHusoGenRegistro>' . $fechaHora . '</sf:FechaHoraHusoGenRegistro><sf:TipoHuella>01</sf:TipoHuella><sf:Huella>' . $huella . '</sf:Huella></sf:RegistroAlta>';

        return ['xml' => $x, 'huella' => $huella, 'fecha_hora' => $fechaHora, 'cuota_total' => $cuotaTotal, 'importe_total' => $importeTotal];
    }

    public static function anulacion(array $d): array
    {
        $fecha = self::fecha($d['factura']['fecha']);
        $fechaHora = self::fechaHora($d['momento']);
        $anterior = $d['anterior'] ?? null;
        $huella = Huella::anulacion($d['emisor']['nif'], $d['factura']['numserie'], $fecha, $anterior['huella'] ?? '', $fechaHora);
        $x = '<sf:RegistroAnulacion><sf:IDVersion>1.0</sf:IDVersion><sf:IDFactura><sf:IDEmisorFacturaAnulada>' . self::esc($d['emisor']['nif']) . '</sf:IDEmisorFacturaAnulada>'
            . '<sf:NumSerieFacturaAnulada>' . self::esc($d['factura']['numserie']) . '</sf:NumSerieFacturaAnulada><sf:FechaExpedicionFacturaAnulada>' . $fecha . '</sf:FechaExpedicionFacturaAnulada></sf:IDFactura>'
            . self::encadenamiento($anterior) . self::sistema($d['sistema'])
            . '<sf:FechaHoraHusoGenRegistro>' . $fechaHora . '</sf:FechaHoraHusoGenRegistro><sf:TipoHuella>01</sf:TipoHuella><sf:Huella>' . $huella . '</sf:Huella></sf:RegistroAnulacion>';

        return ['xml' => $x, 'huella' => $huella, 'fecha_hora' => $fechaHora, 'cuota_total' => '', 'importe_total' => ''];
    }

    /** Sobre SOAP 1.1 con RegFactuSistemaFacturacion. $registros: fragmentos XML de alta/anulación. */
    public static function envio(array $obligado, array $registros): string
    {
        if (empty($registros) || count($registros) > self::MAX_REGISTROS) {
            throw new RuntimeException('Un envío admite entre 1 y ' . self::MAX_REGISTROS . ' registros');
        }
        $cuerpo = '';
        foreach ($registros as $r) {
            $cuerpo .= '<sfLR:RegistroFactura>' . $r . '</sfLR:RegistroFactura>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header/><soapenv:Body>'
            . '<sfLR:RegFactuSistemaFacturacion xmlns:sfLR="' . self::NS_LR . '" xmlns:sf="' . self::NS_SF . '"><sfLR:Cabecera><sf:ObligadoEmision><sf:NombreRazon>'
            . self::esc($obligado['nombre']) . '</sf:NombreRazon><sf:NIF>' . self::esc($obligado['nif']) . '</sf:NIF></sf:ObligadoEmision></sfLR:Cabecera>'
            . $cuerpo . '</sfLR:RegFactuSistemaFacturacion></soapenv:Body></soapenv:Envelope>';
    }

    /**
     * Lee la respuesta de la AEAT (o un Fault SOAP). Rechaza DOCTYPE/entidades.
     * @return array{fault:bool,mensaje:string,estado_envio:string,csv:string,espera:int,lineas:array}
     */
    public static function respuesta(string $xml): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('Respuesta no válida: contiene DOCTYPE o entidades');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (false === $ok) {
            throw new RuntimeException('Respuesta no es XML válido');
        }
        $texto = static function ($ctx, string $nombre) use ($doc): string {
            $n = ($ctx ?? $doc)->getElementsByTagNameNS('*', $nombre);
            return $n->length ? trim($n->item(0)->textContent) : '';
        };
        if ($doc->getElementsByTagNameNS('*', 'Fault')->length) {
            return ['fault' => true, 'mensaje' => trim($texto(null, 'faultcode') . ' ' . $texto(null, 'faultstring')), 'estado_envio' => '', 'csv' => '', 'espera' => 60, 'lineas' => []];
        }
        $estado = $texto(null, 'EstadoEnvio');
        if ('' === $estado) {
            throw new RuntimeException('Respuesta no reconocida: falta EstadoEnvio');
        }
        $lineas = [];
        foreach ($doc->getElementsByTagNameNS('*', 'RespuestaLinea') as $l) {
            $lineas[] = [
                'numserie' => $texto($l, 'NumSerieFactura'),
                'operacion' => $texto($l, 'TipoOperacion'),
                'estado' => $texto($l, 'EstadoRegistro'),
                'codigo' => $texto($l, 'CodigoErrorRegistro'),
                'descripcion' => $texto($l, 'DescripcionErrorRegistro'),
            ];
        }
        return ['fault' => false, 'mensaje' => '', 'estado_envio' => $estado, 'csv' => $texto(null, 'CSV'), 'espera' => (int)$texto(null, 'TiempoEsperaEnvio') ?: 60, 'lineas' => $lineas];
    }

    private static function detalle(array $l): string
    {
        $x = '<sf:DetalleDesglose><sf:Impuesto>01</sf:Impuesto><sf:ClaveRegimen>01</sf:ClaveRegimen>';
        if ($l['iva'] <= 0) {
            // shortcut: todo IVA 0 % se declara exento por art. 20 (E1); distinguir no sujeto/otras exenciones cuando haga falta
            return $x . '<sf:OperacionExenta>E1</sf:OperacionExenta><sf:BaseImponibleOimporteNoSujeto>' . self::importe($l['base']) . '</sf:BaseImponibleOimporteNoSujeto></sf:DetalleDesglose>';
        }
        $x .= '<sf:CalificacionOperacion>S1</sf:CalificacionOperacion><sf:TipoImpositivo>' . self::importe($l['iva']) . '</sf:TipoImpositivo>'
            . '<sf:BaseImponibleOimporteNoSujeto>' . self::importe($l['base']) . '</sf:BaseImponibleOimporteNoSujeto><sf:CuotaRepercutida>' . self::importe($l['cuota']) . '</sf:CuotaRepercutida>';
        if (($l['recargo'] ?? 0) > 0) {
            $x .= '<sf:TipoRecargoEquivalencia>' . self::importe($l['recargo']) . '</sf:TipoRecargoEquivalencia><sf:CuotaRecargoEquivalencia>' . self::importe($l['cuota_recargo']) . '</sf:CuotaRecargoEquivalencia>';
        }
        return $x . '</sf:DetalleDesglose>';
    }

    private static function encadenamiento(?array $a): string
    {
        if (null === $a) {
            return '<sf:Encadenamiento><sf:PrimerRegistro>S</sf:PrimerRegistro></sf:Encadenamiento>';
        }
        return '<sf:Encadenamiento><sf:RegistroAnterior><sf:IDEmisorFactura>' . self::esc($a['nif']) . '</sf:IDEmisorFactura><sf:NumSerieFactura>' . self::esc($a['numserie'])
            . '</sf:NumSerieFactura><sf:FechaExpedicionFactura>' . self::fecha($a['fecha']) . '</sf:FechaExpedicionFactura><sf:Huella>' . $a['huella'] . '</sf:Huella></sf:RegistroAnterior></sf:Encadenamiento>';
    }

    private static function sistema(array $s): string
    {
        return '<sf:SistemaInformatico><sf:NombreRazon>' . self::esc($s['nombre']) . '</sf:NombreRazon><sf:NIF>' . self::esc($s['nif']) . '</sf:NIF><sf:NombreSistemaInformatico>VerifactuFacturalo</sf:NombreSistemaInformatico>'
            . '<sf:IdSistemaInformatico>' . self::esc($s['id']) . '</sf:IdSistemaInformatico><sf:Version>' . self::esc($s['version']) . '</sf:Version><sf:NumeroInstalacion>' . self::esc($s['instalacion']) . '</sf:NumeroInstalacion>'
            . '<sf:TipoUsoPosibleSoloVerifactu>S</sf:TipoUsoPosibleSoloVerifactu><sf:TipoUsoPosibleMultiOT>N</sf:TipoUsoPosibleMultiOT><sf:IndicadorMultiplesOT>N</sf:IndicadorMultiplesOT></sf:SistemaInformatico>';
    }
}
