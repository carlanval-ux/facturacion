<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

/**
 * Pestaña "Anticipos" dentro de presupuesto/pedido/albarán (con botón de alta) y de la ficha del cliente.
 * $c es el controlador al que está ligada la extensión; lo protegido (addListView, validateFormToken) lo llama la propia extensión.
 */
final class Vista
{
    const TAB = 'ListAnticipo';

    public static function busqueda($vista)
    {
        return $vista->addSearchFields(['codigodoc', 'observaciones'])->addOrderBy(['fecha', 'id'], 'date', 2);
    }

    public static function ajustar($c, bool $conAlta): void
    {
        $c->setSettings(self::TAB, 'btnNew', false);
        $c->setSettings(self::TAB, 'btnDelete', false);
        if ($conAlta) {
            $c->tab(self::TAB)->addButton(['type' => 'modal', 'action' => 'new-anticipo', 'color' => 'success', 'icon' => 'fa-solid fa-plus', 'label' => 'new-anticipo']);
        }
    }

    /** Filtro de la pestaña (documento: iddoc + modelo; cliente: codcliente) y aviso de anticipos sin aplicar. */
    public static function cargar($c, string $viewName, $view, bool $esDocumento): void
    {
        if ($viewName !== self::TAB) {
            return;
        }
        $code = $c->request->queryOrInput('code');
        if (empty($code)) {
            return;
        }
        $where = $esDocumento ?
            [Where::eq('modelo', $c->getModelClassName()), Where::eq('iddoc', (int)$code)] :
            [Where::eq('codcliente', $code)];
        $view->loadData('', $where);
        Anticipos::avisarPendientes($where);
    }

    /** Acción "new-anticipo" del modal. Devuelve siempre true: sigue la carga normal de la página. */
    public static function accion($c, string $action, bool $tokenOk): bool
    {
        if ($action !== 'new-anticipo') {
            return true;
        }
        if (false === $c->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }
        if (false === $tokenOk) {
            return true;
        }
        $r = $c->request;
        Anticipos::registrar(
            $c->getModel(),
            (float)str_replace(',', '.', (string)$r->input('importe', '0')),
            (float)str_replace(',', '.', (string)$r->input('porcentaje', '0')),
            (string)$r->input('fecha', ''),
            (string)$r->input('codpago', ''),
            (string)$r->input('observaciones', ''),
            (string)$c->user->nick
        );
        return true;
    }
}
