<?php
/**
 * VerifactuFacturalo - Apartado «Verifactu» en la ficha de la empresa: certificado, vencimiento, check «Modo pruebas», modo, botones
 * «Cambiar certificado Verifactu» y «Comprobar Verifactu», y pestañas «Requerimientos» (registros y su estado) y «Eventos».
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Servicio;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpConfig;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpRegistro;

class EditEmpresa
{
    public function createViews(): Closure
    {
        return function () {
            $this->addEditView('EditVfpConfig', 'VfpConfig', 'verifactu', 'fa-solid fa-shield-halved')
                ->setSettings('btnNew', false)
                ->setSettings('btnDelete', false);
            $this->tab('EditVfpConfig')->addButton(['action' => 'vfp-cert', 'color' => 'warning', 'icon' => 'fa-solid fa-certificate', 'label' => 'vfp-change-certificate', 'type' => 'modal']);
            $this->tab('EditVfpConfig')->addButton(['action' => 'vfp-check', 'color' => 'info', 'icon' => 'fa-solid fa-stethoscope', 'label' => 'vfp-check', 'type' => 'action']);

            $this->addListView('ListVfpRegistro', 'VfpRegistro', 'vfp-requirements', 'fa-solid fa-list-check')
                ->addSearchFields(['numserie', 'csv', 'mensaje'])
                ->addOrderBy(['id'], 'date', 2)
                ->addFilterSelect('estado', 'state', 'estado', [
                    ['code' => '', 'description' => '------'],
                    ['code' => 'pendiente', 'description' => 'pendiente'],
                    ['code' => 'aceptado', 'description' => 'aceptado'],
                    ['code' => 'aceptado_errores', 'description' => 'aceptado_errores'],
                    ['code' => 'rechazado', 'description' => 'rechazado'],
                    ['code' => 'error', 'description' => 'error'],
                ])
                ->setSettings('btnNew', false)
                ->setSettings('clickable', false);
            $this->tab('ListVfpRegistro')->addButton(['action' => 'vfp-retry', 'color' => 'warning', 'icon' => 'fa-solid fa-rotate-right', 'label' => 'vfp-retry']);
            $this->tab('ListVfpRegistro')->addButton(['action' => 'vfp-cancel', 'color' => 'danger', 'icon' => 'fa-solid fa-ban', 'label' => 'vfp-cancel-record', 'confirm' => true]);

            $this->addListView('ListVfpEvento', 'VfpEvento', 'vfp-events', 'fa-solid fa-clock-rotate-left')
                ->addOrderBy(['id'], 'date', 2)
                ->setSettings('btnNew', false)
                ->setSettings('clickable', false);
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if (false === in_array($action, ['vfp-cert', 'vfp-check', 'vfp-retry', 'vfp-cancel'], true)) {
                return true;
            } elseif (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return true;
            } elseif (false === $this->validateFormToken()) {
                return true;
            }
            $id = (int)$this->request->input('code', $this->request->query('code', 0));
            if ($id <= 0) {
                return true;
            }

            switch ($action) {
                case 'vfp-cert':
                    $file = $this->request->files->get('vfpcertfile');
                    $pass = (string)$this->request->input('vfpcertpass', '');
                    if (empty($file) || false === $file->isValid() || '' === $pass) {
                        Tools::log()->warning('vfp-cert-missing');
                        return true;
                    }
                    $error = Servicio::guardarCertificado($id, (string)file_get_contents($file->getPathname()), $pass, $this->user->nick);
                    @unlink($file->getPathname());
                    '' === $error ? Tools::log()->notice('vfp-cert-saved') : Tools::log()->error('vfp-cert-error', ['%error%' => $error]);
                    return true;

                case 'vfp-check':
                    foreach (Servicio::comprobar($id) as [$ok, $texto]) {
                        $ok ? Tools::log()->notice($texto) : Tools::log()->warning($texto);
                    }
                    return true;

                default:
                    $codes = array_filter(array_map('intval', (array)$this->request->request->getArray('codes')));
                    if (empty($codes)) {
                        Tools::log()->warning('no-selected-item');
                        return true;
                    }
                    foreach (VfpRegistro::all([Where::in('id', implode(',', $codes)), Where::eq('idempresa', $id)]) as $r) {
                        if ($action === 'vfp-retry' && in_array($r->estado, [VfpRegistro::ERROR, VfpRegistro::PENDIENTE], true)) {
                            $r->proximo_intento = null;
                            $r->save();
                        } elseif ($action === 'vfp-cancel') {
                            Servicio::anular($r, $this->user->nick) ?: Tools::log()->warning('vfp-cancel-not-applied', ['%code%' => $r->numserie]);
                        }
                    }
                    return true;
            }
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if (false === in_array($viewName, ['EditVfpConfig', 'ListVfpRegistro', 'ListVfpEvento'], true)) {
                return;
            }
            $id = (int)$this->getViewModelValue($this->getMainViewName(), 'idempresa');
            if ($id <= 0) {
                return;
            }
            if ('EditVfpConfig' === $viewName) {
                VfpConfig::deEmpresa($id); // crea los ajustes (modo pruebas marcado) si faltan
                $view->loadData($id);
                return;
            }
            $view->loadData('', [Where::eq('idempresa', $id)]);
        };
    }
}
