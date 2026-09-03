<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Application\Services;

use Illuminate\Support\Str;
use Sodeker\Attachments\Contracts\AttachmentRegistryPort;
use Sodeker\Attachments\Domain\Entities\Attachment;
use Sodeker\Attachments\Domain\Repositories\AttachmentRepositoryInterface;
use Sodeker\Attachments\Domain\ValueObjects\StoredAttachment;

/**
 * Caso de uso: dejar constancia de un adjunto ya escrito en el almacenamiento.
 *
 * ES DELIBERADAMENTE FINO. Todo lo que había que decidir —qué formato es, dónde va, en qué
 * disco— ya se decidió al escribirlo, y viaja resuelto dentro de `StoredAttachment`. Aquí solo se
 * traduce ese resultado a una fila.
 *
 * EL UUID SE GENERA AQUÍ y no en el repositorio: es una decisión del caso de uso, y generarlo
 * antes de guardar permite conocerlo sin depender de lo que devuelva la base.
 */
final class RegisterAttachmentService implements AttachmentRegistryPort
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
    ) {}

    public function register(StoredAttachment $stored, string $actorUuid): Attachment
    {
        return $this->attachments->save(Attachment::create(
            uuid: (string) Str::ulid(),
            name: $stored->originalName,
            // DÓNDE QUEDÓ, no dónde escribe hoy el cliente. Es lo que permite que cambiarle el
            // destino a un cliente no rompa la lectura de lo que ya tenía guardado.
            storageLocation: (string) $stored->location,
            // Se persiste la CLAVE, nunca un enlace: una URL guardada deja de servir en cuanto
            // cambia el almacenamiento, y para un documento privado nunca debió existir.
            url: $stored->key,
            extension: $stored->extension,
            size: $stored->sizeBytes,
            createdBy: $actorUuid,
            updatedBy: $actorUuid,
        ));
    }

    public function find(string $uuid): ?Attachment
    {
        return $this->attachments->findByUuid($uuid);
    }

    public function forget(string $uuid): bool
    {
        return $this->attachments->deleteByUuid($uuid);
    }
}
