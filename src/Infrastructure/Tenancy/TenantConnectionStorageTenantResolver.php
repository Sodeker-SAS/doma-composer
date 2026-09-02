<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Tenancy;

use Sodeker\Attachments\Domain\Repositories\ResolvesStorageTenantInterface;
use RuntimeException;

/**
 * Resuelve el tenant a partir del nombre de la base de datos que quedó apuntada en la conexión
 * `tenant`.
 *
 * POR QUÉ ESA FUENTE Y NO EL REQUEST: los dos middlewares que fijan el tenant —`tenant.api`
 * para la API y `SetTenantConnection` para la web— terminan haciendo lo mismo,
 * `Config::set('database.connections.tenant.database', …)`. Leer de ahí funciona en los dos
 * caminos sin que el módulo tenga que saber cuál de los dos se recorrió, y sigue funcionando
 * fuera de una petición HTTP.
 *
 * POR QUÉ EL NOMBRE DE LA BD Y NO EL SLUG: el slug solo está en el objeto tenant del request, y
 * además es editable desde Doma. El nombre de la base es estable —cambiarlo implicaría mover la
 * base entera— y esa estabilidad es justo lo que se necesita: si el segmento cambiara, los
 * adjuntos ya guardados quedarían fuera del prefijo por el que se los busca.
 */
final class TenantConnectionStorageTenantResolver implements ResolvesStorageTenantInterface
{
    public function storageKey(): string
    {
        $database = trim((string) config('database.connections.tenant.database', ''));

        if ($database === '') {
            // Sin tenant resuelto no se puede decidir dónde va el archivo. Fallar aquí es
            // preferible a escribirlo en un prefijo compartido entre clientes.
            throw new RuntimeException(
                'No hay un tenant resuelto: el adjunto no puede almacenarse sin saber a qué cliente pertenece.'
            );
        }

        // La base puede llamarse "acme-prod" o "Acme_Prod"; el segmento de ruta se normaliza a
        // un alfabeto seguro y estable.
        $key = strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $database) ?? '');
        $key = trim($key, '_');

        if ($key === '' || ! preg_match('/^[a-z0-9]/', $key)) {
            $key = 'tenant_'.$key;
        }

        return $key;
    }
}
