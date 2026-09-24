<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

/**
 * Resultado de archivar un archivo de sistema.
 *
 * ES PARA EL LOG DE QUIEN LLAMA, NO PARA PERSISTIR: el puerto de archivo no deja rastro en base de
 * datos, y quien lo usa tampoco debería. Todo lo necesario para encontrar el archivo ya está en su
 * clave, que es la misma en el origen y en el destino.
 */
final readonly class ArchivedFile
{
    public function __construct(
        /** Ruta relativa dentro del destino, exactamente la que se pidió. */
        public string $key,
        /** Nombre del disco de `config/filesystems.php` donde quedó. */
        public string $destination,
        public int $sizeBytes,
        /** Huella del archivo local, calculada antes de subirlo. */
        public string $sha256,
        /** Intentos que necesitó la subida; 0 si ya estaba archivado. */
        public int $attempts,
        /** true si la clave ya existía con el mismo tamaño y no se volvió a subir. */
        public bool $alreadyArchived,
    ) {}
}
