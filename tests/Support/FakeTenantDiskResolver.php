<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use Sodeker\Attachments\Domain\Repositories\ResolvesTenantDiskInterface;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;

/**
 * Resolvedor de destinos con un mapa en memoria, para probar el reparto sin tocar `landlord`.
 *
 * REPRODUCE LA CADENA DE BÚSQUEDA REAL: propósito exacto → comodín `attachments` → disco por
 * defecto. Es lo que se quiere afirmar en las pruebas, así que el doble tiene que respetarla o
 * no probaría nada.
 */
final class FakeTenantDiskResolver implements ResolvesTenantDiskInterface
{
    /** @var list<string> propósitos consultados, en orden */
    public array $asked = [];

    /**
     * @param  array<string, AttachmentLocation>  $byPurpose  propósito → destino declarado
     */
    public function __construct(
        private readonly array $byPurpose = [],
        private readonly ?AttachmentLocation $default = null,
    ) {}

    public function locationFor(string $purpose): AttachmentLocation
    {
        $this->asked[] = $purpose;

        return $this->byPurpose[$purpose]
            ?? $this->byPurpose['attachments']
            ?? $this->default
            ?? AttachmentLocation::localDisk('public');
    }
}
