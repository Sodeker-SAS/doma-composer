<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

/**
 * La ubicación apunta a un disco local que no está declarado en `config/filesystems.php`.
 *
 * POR QUÉ FALLA EN LUGAR DE IMPROVISAR EL DISCO: antes, un destino local desconocido se
 * resolvía fabricando una definición al vuelo (`storage/app/<destino>`). Eso convertía un error
 * de tipeo en la tabla del tenant —escribir `S2` donde debía decir `s3`— en una carpeta nueva
 * creada en silencio: los adjuntos se escribían fuera de cualquier disco declarado, no entraban
 * en los respaldos ni en los despliegues, y nadie se enteraba porque la lectura seguía
 * funcionando (la fila guardaba esa misma ubicación inventada).
 *
 * Un destino que nadie declaró es una configuración incorrecta, no un destino nuevo. Fallar
 * aquí lo hace visible en el momento de la carga, que es cuando todavía se puede corregir.
 *
 * NO ES CULPA DE QUIEN LLAMA: el consumidor mandó un archivo válido y una categoría válida; lo
 * que está mal es la fila de `tenant_disks` o la falta de una declaración en `filesystems.php`.
 * Se traduce a 500 con mensaje genérico y el detalle queda en el log.
 */
final class UnknownAttachmentDiskException extends AttachmentException
{
    /**
     * @param  string  $disk  Nombre del disco que se buscó en `filesystems.disks`.
     * @param  string  $location  Ubicación completa de la que salió, para poder rastrear la fila.
     */
    public static function forDisk(string $disk, string $location): self
    {
        return new self(
            "El disco «{$disk}» de la ubicación «{$location}» no está declarado en "
            .'config/filesystems.php. Revisa la fila de `tenant_disks` de este cliente: el '
            .'driver debe ser `s3` o el nombre de un disco existente.'
        );
    }

    public function isCallerFault(): bool
    {
        return false;
    }
}
