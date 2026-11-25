<?php
namespace FacturaScripts\Plugins\Facturalo\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\CuentaBanco;
use PHP_IBAN\IBAN;

/** Alta rapida de una cuenta bancaria desde el desplegable de Formas de pago («Crear…» al estilo Odoo). */
final class CuentaBancoRapida
{
    /** @return array{ok:bool, existente?:bool, codcuenta?:string, descripcion?:string, error?:string} */
    public static function crear(string $iban, string $descripcion, ?int $idempresa = null): array
    {
        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        if ('' === $iban) {
            return ['ok' => false, 'error' => 'IBAN obligatorio'];
        }
        // se valida siempre, con independencia del ajuste general «Validar IBAN»
        if (false === (new IBAN($iban))->Verify()) {
            return ['ok' => false, 'error' => 'IBAN no válido'];
        }

        $idempresa = $idempresa ?: Tools::settings('default', 'idempresa');
        // mismo IBAN en la misma empresa: no se duplica, se devuelve la existente para seleccionarla
        $existente = new CuentaBanco();
        if ($existente->loadWhere([Where::eq('iban', $iban), Where::eq('idempresa', $idempresa)])) {
            return ['ok' => true, 'existente' => true, 'codcuenta' => (string)$existente->codcuenta, 'descripcion' => (string)$existente->descripcion];
        }

        $cuenta = new CuentaBanco();
        $cuenta->iban = $iban;
        $cuenta->descripcion = '' !== trim($descripcion) ? trim($descripcion) : 'Cuenta ' . substr($iban, -4);
        $cuenta->idempresa = $idempresa;
        if (false === $cuenta->save()) {
            return ['ok' => false, 'error' => 'No se pudo guardar la cuenta'];
        }

        return ['ok' => true, 'codcuenta' => (string)$cuenta->codcuenta, 'descripcion' => (string)$cuenta->descripcion];
    }
}
