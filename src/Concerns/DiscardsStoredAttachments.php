<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Concerns;

use Sodeker\Attachments\Contracts\AttachmentStoragePort;
use Sodeker\Attachments\Domain\ValueObjects\StoredAttachment;
use Throwable;

/**
 * Compensación de adjuntos ya escritos cuando la operación que los acompañaba no se completa.
 *
 * POR QUÉ HACE FALTA: escribir el archivo y registrar su fila son dos operaciones sobre sistemas
 * distintos, y solo una de ellas es transaccional. Si la transacción se deshace, los bytes siguen
 * en el almacenamiento sin ninguna fila que los apunte.
 *
 * Y ESOS HUÉRFANOS NO SE PUEDEN RECUPERAR DESPUÉS: la tabla genérica de adjuntos no guarda a
 * quién pertenece cada archivo —esa relación vive en la pivote de cada módulo consumidor—, así
 * que un objeto sin fila no se puede atribuir a nada salvo parseando su ruta. Por eso se limpia
 * en el momento, que es el único en el que todavía se sabe qué se escribió.
 *
 * VIVE EN UN TRAIT COMPARTIDO porque todos los caminos que suben adjuntos —los tres de Estudios
 * y los seguimientos— necesitan exactamente la misma garantía, y la política de qué hacer
 * cuando la limpieza falla debe ser una sola.
 */
trait DiscardsStoredAttachments
{
    /**
     * Borra del almacenamiento los objetos que sí llegaron a escribirse.
     *
     * SE HACE CON EL MAYOR CUIDADO POSIBLE: un fallo limpiando no debe tapar el error original,
     * que es el que el consumidor necesita ver. Un huérfano en el almacenamiento es un problema
     * menor y recuperable; perder la causa real del fallo, no.
     *
     * @param  list<StoredAttachment>  $stored
     */
    private function discardStoredAttachments(AttachmentStoragePort $storage, array $stored): void
    {
        foreach ($stored as $attachment) {
            try {
                // Se borra en la ubicación donde REALMENTE se escribió, no en la que el cliente
                // tenga configurada ahora: entre la escritura y este borrado no puede haber
                // cambiado, pero pasarla explícita es lo que hace que el borrado sea correcto
                // aunque el destino del cliente se toque en mitad de un proceso largo.
                $storage->delete((string) $attachment->location, $attachment->key);
            } catch (Throwable) {
                // Intencionadamente en silencio: ver el comentario de arriba.
            }
        }
    }
}
