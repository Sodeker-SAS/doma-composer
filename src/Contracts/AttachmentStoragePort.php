<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Contracts;

use Sodeker\Attachments\Domain\Exceptions\AttachmentStorageFailedException;
use Sodeker\Attachments\Domain\Exceptions\AttachmentTooLargeException;
use Sodeker\Attachments\Domain\Exceptions\UnsupportedAttachmentTypeException;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;
use Sodeker\Attachments\Domain\ValueObjects\StoredAttachment;
use Sodeker\Attachments\Infrastructure\Http\UploadedFileAttachmentFactory;

/**
 * Puerto de almacenamiento de adjuntos.
 *
 * El módulo consumidor (Studies hoy, cualquier otro mañana) depende de este contrato y no del
 * módulo `Attachments`: entrega el contenido y recibe dónde quedó, sin acoplarse a cómo ni
 * dónde se guarda.
 *
 * POR QUÉ YA NO RECIBE UN `UploadedFile`: la firma anterior describía «un archivo que llegó por
 * un formulario HTTP», lo que ataba el contrato a un único origen. Ahora hay tres —la web de
 * Fintegra, F16 y Certus— y habrá más, así que el puerto habla de contenido, no de peticiones.
 * La traducción desde `UploadedFile` la hace {@see UploadedFileAttachmentFactory}
 * en la frontera HTTP, que es donde corresponde.
 *
 * POR QUÉ DEVUELVE UNA CLAVE Y NO UNA URL: ver {@see StoredAttachment}. En resumen: una URL
 * persistida deja de servir en cuanto cambia el almacenamiento, y para un documento privado
 * nunca debió existir.
 *
 * TODAVÍA NO SABE DE PRIVACIDAD: `store()` no recibe la visibilidad, así que todo se escribe en
 * el mismo disco. Quien marca un adjunto como privado lo hace en su propia pivote, y eso solo
 * oculta el enlace al leer: el objeto sigue en el disco por defecto, que hoy es público. Es un
 * límite conocido y aceptado, documentado con su plan de salida en la nota «Documentos privados»
 * de `config/attachments.php`. Cuando toque resolverlo, la visibilidad entra por AQUÍ —es el
 * módulo quien elige disco y visibilidad, no el consumidor—.
 */
interface AttachmentStoragePort
{
    /**
     * Valida el contenido y lo persiste en el almacenamiento configurado.
     *
     * TOMA POSESIÓN DEL FLUJO: la implementación cierra el stream del binario pase lo que pase,
     * también cuando rechaza el archivo. Quien llama no debe volver a usarlo después, pero sí
     * puede cerrarlo por su cuenta — {@see AttachmentBinary::close()} es idempotente a
     * propósito, justo para que la posesión compartida no obligue a llevar la cuenta.
     *
     * @throws UnsupportedAttachmentTypeException formato no permitido o incoherente con la extensión
     * @throws AttachmentTooLargeException excede el máximo por archivo
     * @throws AttachmentStorageFailedException fallo escribiendo
     */
    public function store(AttachmentBinary $binary, AttachmentOwner $owner): StoredAttachment;

    /**
     * Abre el contenido del adjunto para servirlo. Devuelve null si el objeto ya no está.
     *
     * ES EL CAMINO DE LECTURA CONTROLADO, y la diferencia con {@see self::url()} es quién hace
     * el viaje al almacenamiento. Con una URL lo hace el navegador por su cuenta: no lleva
     * sesión, así que sobre el disco local —que nginx sirve sin pasar por Laravel— cualquiera
     * con el enlace lee el documento. Con el flujo lo hace la aplicación, que puede comprobar
     * antes quién pregunta. Es lo que permite tener documentos privados de verdad sin esperar a
     * que el almacenamiento sea un bucket privado.
     *
     * QUIEN LLAMA CIERRA EL FLUJO. Al servirlo por HTTP lo cierra la respuesta al vaciarlo.
     *
     * @param  string  $storageLocation  El valor de `attachments.storage_location`, tal cual.
     * @return resource|null
     */
    public function readStream(string $storageLocation, string $key);

    /**
     * Elimina un adjunto. Devuelve false si no existía.
     *
     * @param  string  $storageLocation  El valor de `attachments.storage_location`, tal cual.
     */
    public function delete(string $storageLocation, string $key): bool;

    /**
     * URL de lectura, generada al momento y nunca persistida: ruta pública en disco local, URL
     * firmada con vencimiento cuando el almacenamiento sea un bucket privado.
     *
     * RECIBE LA UBICACIÓN GUARDADA CON EL ADJUNTO y no la resuelve por su cuenta: el archivo hay
     * que buscarlo donde se escribió, no donde ese cliente escribiría hoy. Es lo que permite
     * cambiarle el destino a un cliente sin migrar nada.
     *
     * @param  string  $storageLocation  El valor de `attachments.storage_location`, tal cual.
     *                                   Vacío o nulo se interpreta como el disco por defecto,
     *                                   que es donde están los adjuntos anteriores a la columna.
     */
    public function url(string $storageLocation, string $key, int $expiresInMinutes = 5): ?string;
}
