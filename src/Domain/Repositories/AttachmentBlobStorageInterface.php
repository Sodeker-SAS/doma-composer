<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Repositories;

use Sodeker\Attachments\Domain\Exceptions\AttachmentStorageFailedException;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;

/**
 * Puerto de salida del módulo: dónde acaban físicamente los bytes.
 *
 * POR QUÉ ES LA PIEZA CLAVE DE TODO ESTO: es la única frontera que hay que cruzar para pasar de
 * disco local a S3. El caso de uso, los value objects y los módulos consumidores hablan de
 * ubicaciones, claves y flujos, nunca de discos ni de buckets. Cuando el bucket esté listo, se
 * cambia la implementación (o simplemente la ubicación que se le pasa) y nada más se entera.
 *
 * TODAS LAS OPERACIONES RECIBEN LA UBICACIÓN, y esa es la diferencia con la versión anterior.
 * Antes el adaptador resolvía el disco por su cuenta, lo que tenía dos consecuencias malas: no
 * podía elegir destino según qué archivo era —no tenía ese contexto— y al leer o borrar usaba
 * siempre el destino VIGENTE, no aquel donde el archivo se había escrito. Ahora quien llama dice
 * dónde, y para leer basta con la ubicación guardada en la fila.
 *
 * Y ES TAMBIÉN LA PUERTA A LO SIGUIENTE: si algún día los archivos grandes deben subirse
 * directo al bucket con URL prefirmada, o si el ecosistema centraliza los adjuntos en Doma, eso
 * es otra implementación de este mismo puerto —no un rediseño de los módulos que lo usan—.
 */
interface AttachmentBlobStorageInterface
{
    /**
     * Escribe el contenido en la clave indicada, dentro de esa ubicación.
     *
     * @throws AttachmentStorageFailedException
     */
    public function put(AttachmentLocation $location, string $key, AttachmentBinary $binary): void;

    /**
     * Abre el objeto para leerlo. Devuelve null si no existe en esa ubicación.
     *
     * POR QUÉ SE LEE EL CONTENIDO Y NO BASTA CON {@see self::url()}: una URL sirve para que el
     * navegador vaya SOLO al almacenamiento, y en ese viaje no hay sesión que valga. Con el
     * disco local eso significa que cualquiera con el enlace lee el documento, aunque esté
     * marcado como privado. Devolver el flujo permite que la aplicación compruebe primero quién
     * pregunta y sirva el archivo ella misma.
     *
     * DEVUELVE UN FLUJO Y NO EL CONTENIDO por la misma razón por la que se escribe con
     * `writeStream`: un adjunto puede pesar decenas de MB y no tiene por qué caber entero en
     * memoria para poder enviarse.
     *
     * QUIEN LLAMA SE QUEDA CON EL FLUJO y es responsable de cerrarlo — al servirlo por HTTP lo
     * cierra la propia respuesta al terminar de escribirlo.
     *
     * @return resource|null
     */
    public function readStream(AttachmentLocation $location, string $key);

    /** Elimina el objeto. Devuelve false si no existía. */
    public function delete(AttachmentLocation $location, string $key): bool;

    public function exists(AttachmentLocation $location, string $key): bool;

    /**
     * URL de lectura del objeto, generada en el momento y NUNCA persistida.
     *
     * Hoy resuelve a la ruta pública del disco local. Con S3 y un bucket privado, será una URL
     * firmada con vencimiento; por eso recibe la duración y por eso no se guarda en la base de
     * datos: una URL almacenada quedaría inservible en cuanto cambie el almacenamiento.
     */
    public function url(AttachmentLocation $location, string $key, int $expiresInMinutes = 5): ?string;
}
