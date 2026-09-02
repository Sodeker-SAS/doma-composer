<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Contracts;

use Sodeker\Attachments\Domain\Entities\Attachment;
use Sodeker\Attachments\Domain\ValueObjects\StoredAttachment;

/**
 * Segundo contrato del módulo: registrar en base de datos un adjunto ya escrito.
 *
 * POR QUÉ NO ESTÁ EN {@see AttachmentStoragePort}: porque son dos momentos distintos y el
 * consumidor necesita separarlos. Escribir los bytes ocurre FUERA de su transacción —mantenerla
 * abierta mientras se negocia con un proveedor de nube retiene bloqueos durante segundos por
 * causas ajenas a la base—; registrar la fila ocurre DENTRO, junto con la pivote del módulo, para
 * que ambas aparezcan o ninguna.
 *
 * Fundirlos en un solo método obligaría a elegir: o el archivo se sube dentro de la transacción,
 * o la fila se escribe fuera. Las dos opciones son peores que tener dos contratos.
 */
interface AttachmentRegistryPort
{
    /**
     * Deja constancia del adjunto que {@see AttachmentStoragePort::store()} acaba de escribir.
     *
     * Llámalo DENTRO de tu transacción, con el resultado que te devolvió la escritura.
     */
    public function register(StoredAttachment $stored, string $actorUuid): Attachment;

    /**
     * El adjunto vigente con ese uuid, o null si no existe o ya fue dado de baja.
     *
     * PARA QUÉ LO NECESITA EL CONSUMIDOR: para volver a leer o borrar un adjunto hay que saber
     * dónde quedó —ubicación y clave—, y eso vive en la tabla genérica, no en su pivote. Sin
     * este método el consumidor tendría que hacer `join` contra la tabla de adjuntos, es decir,
     * conocer el esquema interno del módulo, que es justo lo que estos contratos evitan.
     *
     * NO COMPRUEBA PERMISOS: el módulo no sabe de quién es el adjunto. Quién puede verlo lo
     * decide el consumidor con su propia pivote, que es la que guarda esa relación.
     */
    public function find(string $uuid): ?Attachment;

    /** Baja lógica del registro. No borra el objeto del almacenamiento. */
    public function forget(string $uuid): bool;
}
