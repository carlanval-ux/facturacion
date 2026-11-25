<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Mod;

use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Aceptacion;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\ModuloActivo;

/** Botones del pie del presupuesto: enviar para aceptación y convertir (solo presupuestos ya guardados). */
class AceptacionBotones implements SalesModInterface
{
    public function apply(SalesDocument &$model, array $formData): void
    {
    }

    public function applyBefore(SalesDocument &$model, array $formData): void
    {
    }

    public function assets(): void
    {
    }

    public function newBtnFields(): array
    {
        return ['aceptacionBtns'];
    }

    public function newFields(): array
    {
        return [];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function renderField(SalesDocument $model, string $field): ?string
    {
        if ('aceptacionBtns' !== $field) {
            return null;
        }
        if ('PresupuestoCliente' !== $model->modelClassName() || empty($model->id()) || false === ModuloActivo::estaActivo('aceptacion')) {
            return '';
        }

        $protection = new MultiRequestProtection();
        $protection->addSeed(Session::user()->nick);
        $token = $protection->newToken();
        $html = '';

        $ultimo = Aceptacion::ultimo((int)$model->id());
        if (null !== $ultimo) {
            $estado = Aceptacion::estadoDe($ultimo, $model);
            $html .= '<span class="badge text-bg-secondary me-2" title="' . Tools::noHtml(Tools::trans('aceptacion-estado-enlace')) . '">'
                . Tools::noHtml(Tools::trans('aceptacion-estado-' . $estado)) . '</span>';
        }
        if ($model->editable) {
            $html .= self::boton(
                Tools::trans(null === $ultimo ? 'aceptacion-enviar' : 'aceptacion-reenviar'),
                'aceptacion-enviar', (int)$model->id(), $token, '', 'fa-solid fa-paper-plane', ''
            );
        } elseif (Aceptacion::convertible($model)) {
            foreach (Aceptacion::destinos($model) as $destino) {
                $html .= self::boton(
                    Tools::trans('aceptacion-convertir-' . $destino),
                    'aceptacion-convertir', (int)$model->id(), $token, $destino, 'fa-solid fa-file-invoice',
                    Tools::trans('aceptacion-confirmar-convertir')
                );
            }
        }
        return $html;
    }

    private static function boton(string $label, string $accion, int $code, string $token, string $destino, string $icon, string $confirmar): string
    {
        $args = json_encode([$accion, $code, $token, $destino, $confirmar], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
        return '<button type="button" class="btn btn-outline-primary me-2" onclick="return flAceptacion(' . htmlspecialchars($args, ENT_QUOTES) . ');">'
            . '<i class="' . $icon . ' me-1"></i>' . Tools::noHtml($label) . '</button>' . self::script();
    }

    /** Envía un formulario POST propio (el pie del documento ya va dentro de otro formulario). */
    private static function script(): string
    {
        static $hecho = false;
        if ($hecho) {
            return '';
        }
        $hecho = true;
        return '<script>function flAceptacion(a){if(a[4]&&!window.confirm(a[4])){return false;}'
            . 'var f=document.createElement("form");f.method="post";f.action="AceptacionAccion";'
            . '[["action",a[0]],["code",a[1]],["multireqtoken",a[2]],["destino",a[3]]].forEach(function(p){'
            . 'var i=document.createElement("input");i.type="hidden";i.name=p[0];i.value=p[1];f.appendChild(i);});'
            . 'document.body.appendChild(f);f.submit();return false;}</script>';
    }
}
