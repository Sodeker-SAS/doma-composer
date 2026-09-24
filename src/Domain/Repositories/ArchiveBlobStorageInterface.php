<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Repositories;

use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Throwable;

/**
 * Operaciones mínimas sobre el destino de archivo.
 *
 * ES DELIBERADAMENTE TONTO: escribe, mide, renombra y borra, y nada más. Las reglas —subir a un
 * temporal, comparar tamaños, no sobrescribir, reintentar— viven en el caso de uso, que así se
 * puede probar con un doble que falla a voluntad sin montar un servidor SFTP.
 */
interface ArchiveBlobStorageInterface
{
    /**
     * Nombre del disco de destino, ya validado: configurado, declarado y no público.
     *
     * @throws ArchiveDestinationException
     */
    public function destination(): string;

    public function exists(string $key): bool;

    /**
     * @throws Throwable si el archivo no existe o el destino no responde
     */
    public function size(string $key): int;

    /**
     * Escribe el flujo completo. No lo cierra: el flujo es de quien lo abrió.
     *
     * @param  resource  $stream
     *
     * @throws Throwable si el destino rechaza la escritura
     */
    public function write(string $key, $stream): void;

    /**
     * @throws Throwable si el destino no puede renombrar
     */
    public function move(string $from, string $to): void;

    /**
     * Borrado de mejor esfuerzo, para limpiar temporales. Nunca lanza: si falla, deja un `.part`
     * huérfano en el destino, que es un problema menor que ocultar el error original.
     */
    public function delete(string $key): void;
}
