<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Contracts;

use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveRejectedException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveStorageFailedException;
use Sodeker\Attachments\Domain\ValueObjects\ArchivedFile;

/**
 * Puerto de archivo de sistema: guarda en el almacenamiento externo un archivo que GENERÓ la
 * aplicación, bajo la clave exacta que decide quien llama.
 *
 * POR QUÉ NO ES {@see AttachmentStoragePort}: aquel recibe contenido de terceros, así que lo
 * contrasta contra un catálogo de formatos, lo nombra con un ULID, lo ubica por tenant y propósito
 * en `tenant_disks` y, si el cliente no declaró destino, cae al disco por defecto. Para una copia
 * de seguridad todo eso sobra o estorba: el nombre ES la información —qué base y de cuándo—, el
 * landlord no es un tenant, el archivo pesa cientos de megas y caer en `public` dejaría una base
 * de datos entera descargable por URL. Este puerto no comparte nada de ese camino.
 *
 * QUÉ GARANTIZA:
 *
 *  - La clave se respeta tal cual, validada para que no pueda salir de la raíz del destino.
 *  - El archivo viaja por streaming: la memoria no crece con su tamaño.
 *  - Se sube a un temporal `.part`, se compara el tamaño y solo entonces se renombra. En el
 *    destino nunca aparece con su nombre final un archivo a medias.
 *  - Lo archivado no se sobrescribe. Repetir la misma subida es inocuo; otra con la misma clave y
 *    distinto contenido se rechaza.
 *  - No escribe nada en base de datos ni necesita un tenant resuelto.
 *
 * NO TIENE `delete()` A PROPÓSITO: lo archivado es permanente. La retención, si la hay, la
 * administra quien opera el destino, no la aplicación que produjo el archivo.
 */
interface ArchiveStoragePort
{
    /**
     * Sube el archivo local al destino de archivo configurado (`attachments.archive.disk`).
     *
     * NO TOMA POSESIÓN DEL ARCHIVO LOCAL: lo lee y lo deja donde estaba. Borrarlo, conservarlo o
     * rotarlo es decisión de quien llama.
     *
     * @param  string  $localPath  Ruta absoluta de un archivo regular y legible.
     * @param  string  $key  Ruta relativa dentro del destino, con extensión:
     *                       `backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump`.
     *
     * @throws ArchiveRejectedException clave inválida, tipo no admitido, firma incorrecta, archivo
     *                                  local ausente o vacío, o clave ya ocupada con otro contenido
     * @throws ArchiveDestinationException destino sin configurar, no declarado o servido en público
     * @throws ArchiveStorageFailedException la subida falló en todos los intentos
     */
    public function store(string $localPath, string $key): ArchivedFile;
}
