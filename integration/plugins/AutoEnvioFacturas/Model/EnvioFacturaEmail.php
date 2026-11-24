<?php
/**
 * AutoEnvioFacturas - Envía automáticamente por email las facturas emitidas (compatible con Verifactu). Nunca envía bocetos y, en ejercicios en modo Verifactu, solo envía facturas ya registradas en Verifactu.
 *
 * Copyright (C) 2026 TI53, SLU - Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @author    Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 * @copyright 2026 TI53, SLU
 * @license   LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\AutoEnvioFacturas\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Log de envíos automáticos de facturas por email.
 */
class EnvioFacturaEmail extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idfactura;

    /** @var string */
    public $codigo;

    /** @var string */
    public $email;

    /** @var string */
    public $fecha;

    /** @var int */
    public $intentos;

    /** @var string esperando-verifactu | programado | enviado | error | sin-email | reenvio */
    public $resultado;

    /** @var string */
    public $mensaje;

    public function clear(): void
    {
        parent::clear();
        $this->intentos = 0;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'envios_facturas_email';
    }
}
