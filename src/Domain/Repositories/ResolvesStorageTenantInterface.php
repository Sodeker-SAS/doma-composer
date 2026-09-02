<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Repositories;

/**
 * Puerto que responde «¿de qué cliente es esta petición?» para segmentar el almacenamiento.
 *
 * POR QUÉ ES UN PUERTO Y NO UNA LECTURA DEL REQUEST: el módulo se usa desde la web (donde el
 * tenant lo fija `SetTenantConnection` a partir de la sesión SSO), desde la API (donde lo fija
 * `tenant.api` a partir de la cabecera) y podría usarse desde un job en cola, donde no hay
 * request en absoluto. El caso de uso no debe conocer ninguno de esos tres caminos.
 */
interface ResolvesStorageTenantInterface
{
    /**
     * Identificador del cliente para usar como segmento de ruta. Debe ser estable en el tiempo:
     * si cambia, los adjuntos ya guardados quedarían fuera del prefijo por el que se buscan.
     */
    public function storageKey(): string;
}
