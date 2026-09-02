<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Application\Services;

use Sodeker\Attachments\Domain\Exceptions\AttachmentTooLargeException;
use Sodeker\Attachments\Domain\Repositories\AttachmentBlobStorageInterface;
use Sodeker\Attachments\Domain\Repositories\ResolvesStorageTenantInterface;
use Sodeker\Attachments\Domain\Repositories\ResolvesTenantDiskInterface;
use Sodeker\Attachments\Domain\ValueObjects\AllowedAttachmentTypes;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentContentType;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentStoragePath;
use Sodeker\Attachments\Domain\ValueObjects\StoredAttachment;
use Sodeker\Attachments\Contracts\AttachmentStoragePort;
use Illuminate\Support\Str;

/**
 * Caso de uso: guardar un adjunto, venga de donde venga.
 *
 * ES EL PUNTO ÚNICO DE VALIDACIÓN, y esa es la razón de que los archivos pasen por Fintegra en
 * lugar de ir directos al almacenamiento. Se comprueba, en este orden:
 *
 *   1. Tamaño — es un entero, sale gratis, y descarta antes de tocar el contenido.
 *   2. Tipo real — se leen los primeros bytes y se exige que el formato esté permitido y que
 *      coincida con la extensión declarada. Ni el nombre del archivo ni el `Content-Type` que
 *      manda el cliente deciden nada: los escribe quien llama, y aquí llaman aplicaciones que
 *      no controlamos.
 *
 * Solo después se escribe. Un ejecutable renombrado a `.pdf` no llega nunca al disco, y cuando
 * el almacenamiento sea S3, tampoco al bucket.
 *
 * ES TAMBIÉN DONDE SE DECIDE EL DESTINO, porque es el único punto que conoce a la vez al dueño
 * del adjunto y los puertos de salida. El adaptador de almacenamiento no puede decidirlo: no
 * sabe de qué archivo se trata.
 */
final class StoreAttachmentService implements AttachmentStoragePort
{
    public function __construct(
        private readonly AttachmentBlobStorageInterface $blobs,
        private readonly ResolvesStorageTenantInterface $tenant,
        private readonly ResolvesTenantDiskInterface $disks,
    ) {}

    public function store(AttachmentBinary $binary, AttachmentOwner $owner): StoredAttachment
    {
        // EL `finally` CUBRE TODO EL MÉTODO, no solo la escritura: un archivo rechazado por
        // tamaño o por formato también trae un descriptor abierto que hay que soltar. Cerrarlo
        // únicamente en el camino feliz dejaba un temporal vivo por cada archivo rechazado
        // hasta el final de la petición — tolerable bajo FPM, no en un worker de cola.
        try {
            $maxBytes = $this->maxSizeBytes();

            if ($binary->sizeBytes > $maxBytes) {
                throw AttachmentTooLargeException::forFile($binary->originalName, $binary->sizeBytes, $maxBytes);
            }

            $contentType = AttachmentContentType::resolve($binary, $this->allowedTypes());

            // El nombre físico es un ULID más la extensión YA VERIFICADA contra el contenido: el
            // nombre que eligió un tercero nunca forma parte de una ruta.
            $path = AttachmentStoragePath::build(
                appPrefix: (string) config('attachments.app_prefix', 'app'),
                tenant: $this->tenant->storageKey(),
                owner: $owner,
                fileName: ((string) Str::ulid()).'.'.$contentType->extension,
            );

            // El destino sale del PROPÓSITO del dueño, no del cliente a secas: así un mismo
            // cliente puede mandar los documentos de sus aplicantes a un bucket y los del
            // inmueble a su disco propio. Ver AttachmentOwner::purpose().
            $location = $this->disks->locationFor($owner->purpose());

            $this->blobs->put($location, $path->key, $binary);

            return new StoredAttachment(
                key: $path->key,
                originalName: $binary->originalName,
                extension: $contentType->extension,
                sizeBytes: $binary->sizeBytes,
                location: $location,
            );
        } finally {
            $binary->close();
        }
    }

    /**
     * @return resource|null
     */
    public function readStream(string $storageLocation, string $key)
    {
        return $this->blobs->readStream($this->location($storageLocation), $key);
    }

    public function delete(string $storageLocation, string $key): bool
    {
        return $this->blobs->delete($this->location($storageLocation), $key);
    }

    public function url(string $storageLocation, string $key, int $expiresInMinutes = 5): ?string
    {
        return $this->blobs->url($this->location($storageLocation), $key, $expiresInMinutes);
    }

    /**
     * Traduce lo que hay guardado en la fila a la ubicación con la que operar.
     *
     * SE PARSEA AQUÍ Y NO EN EL CONSUMIDOR para que el contrato siga siendo de cadenas: quien usa
     * el módulo lee la columna y la pasa tal cual, sin tener que construir un value object ni
     * conocer el formato.
     */
    private function location(string $storageLocation): AttachmentLocation
    {
        return AttachmentLocation::fromString($storageLocation, (string) config('attachments.disk', 'public'));
    }

    /**
     * La política de formatos se construye aquí, en Application, y se le entrega ya hecha al
     * dominio: leer `config()` desde un value object metería el framework donde no va.
     */
    private function allowedTypes(): AllowedAttachmentTypes
    {
        /** @var array<string, string> $signatures */
        $signatures = (array) config('attachments.allowed_types', []);
        /** @var array<string, string> $aliases */
        $aliases = (array) config('attachments.extension_aliases', []);
        /** @var array<string, string> $containerMarkers */
        $containerMarkers = (array) config('attachments.container_markers', []);
        /** @var array<string, int> $searchWindows */
        $searchWindows = (array) config('attachments.signature_search_bytes', []);

        return AllowedAttachmentTypes::fromMap($signatures, $aliases, $containerMarkers, $searchWindows);
    }

    private function maxSizeBytes(): int
    {
        return (int) config('attachments.max_size_bytes', 52428800);
    }
}
