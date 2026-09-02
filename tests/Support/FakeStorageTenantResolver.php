<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use Sodeker\Attachments\Domain\Repositories\ResolvesStorageTenantInterface;

/**
 * Tenant fijo para las pruebas del caso de uso, que no van sobre cómo se resuelve el cliente
 * —eso lo cubre `TenantConnectionStorageTenantResolverTest`— sino sobre qué se hace con él.
 */
final class FakeStorageTenantResolver implements ResolvesStorageTenantInterface
{
    public function __construct(private readonly string $key = 'acme') {}

    public function storageKey(): string
    {
        return $this->key;
    }
}
