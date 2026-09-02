<?php

declare(strict_types=1);

namespace Sodeker\Attachments;

use Illuminate\Support\ServiceProvider;
use Sodeker\Attachments\Application\Services\RegisterAttachmentService;
use Sodeker\Attachments\Application\Services\StoreAttachmentService;
use Sodeker\Attachments\Contracts\AttachmentRegistryPort;
use Sodeker\Attachments\Contracts\AttachmentStoragePort;
use Sodeker\Attachments\Domain\Repositories\AttachmentBlobStorageInterface;
use Sodeker\Attachments\Domain\Repositories\AttachmentRepositoryInterface;
use Sodeker\Attachments\Domain\Repositories\ResolvesStorageTenantInterface;
use Sodeker\Attachments\Domain\Repositories\ResolvesTenantDiskInterface;
use Sodeker\Attachments\Infrastructure\Database\Repositories\EloquentAttachmentRepository;
use Sodeker\Attachments\Infrastructure\Storage\FlysystemAttachmentBlobStorage;
use Sodeker\Attachments\Infrastructure\Tenancy\LandlordTenantDiskResolver;
use Sodeker\Attachments\Infrastructure\Tenancy\TenantConnectionStorageTenantResolver;

final class AttachmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/attachments.php', 'attachments');

        // Contratos públicos → casos de uso. Es lo único que ven los módulos consumidores: uno
        // escribe los bytes, el otro deja constancia en base de datos.
        $this->app->bind(AttachmentStoragePort::class, StoreAttachmentService::class);
        $this->app->bind(AttachmentRegistryPort::class, RegisterAttachmentService::class);

        // Persistencia del registro genérico de adjuntos.
        $this->app->bind(AttachmentRepositoryInterface::class, EloquentAttachmentRepository::class);

        // Puertos de salida del módulo. Cambiar de disco local a S3 es cambiar
        // ATTACHMENTS_DISK; cambiar de Flysystem a otra cosa (un servicio central de adjuntos
        // en Doma, por ejemplo) es cambiar esta línea.
        $this->app->bind(AttachmentBlobStorageInterface::class, FlysystemAttachmentBlobStorage::class);
        $this->app->bindIf(ResolvesStorageTenantInterface::class, TenantConnectionStorageTenantResolver::class);

        // SCOPED Y NO SINGLETON, a propósito: el resolvedor memoriza el disco que encontró para no
        // consultar `landlord` en cada archivo de la misma petición. Un singleton conservaría esa
        // memoria entre peticiones, y en un proceso de larga vida —una cola, Octane— acabaría
        // escribiendo los adjuntos de un cliente en el disco del anterior. `scoped` se reinicia en
        // cada petición y en cada job, que es justo la vida útil que debe tener ese dato.
        $this->app->scopedIf(ResolvesTenantDiskInterface::class, LandlordTenantDiskResolver::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/attachments.php' => config_path('attachments.php'),
        ], 'attachments-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/0001_01_01_000000_create_attachments_table.php.stub' => database_path('migrations/0001_01_01_000000_create_attachments_table.php'),
            __DIR__.'/../database/migrations/0001_01_01_000001_create_tenant_disks_table.php.stub' => database_path('migrations/0001_01_01_000001_create_tenant_disks_table.php'),
        ], 'attachments-migrations');
    }
}
