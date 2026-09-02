<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Repositories;

use Sodeker\Attachments\Domain\Entities\Attachment;

/**
 * Persistencia del registro de adjuntos.
 *
 * Es el único puerto del módulo que habla de base de datos; los otros tres hablan de bytes y de
 * clientes. Se separa por la misma razón: el día que los adjuntos vivan en un servicio central,
 * esta interfaz pasa a implementarse contra HTTP y el resto del módulo no se entera.
 */
interface AttachmentRepositoryInterface
{
    public function save(Attachment $attachment): Attachment;

    /** El adjunto vigente con ese uuid, o null si no existe o ya fue dado de baja. */
    public function findByUuid(string $uuid): ?Attachment;

    /** Baja lógica. Devuelve false si no existía. */
    public function deleteByUuid(string $uuid): bool;
}
