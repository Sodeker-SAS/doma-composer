<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sodeker\Attachments\Domain\Exceptions\AttachmentStorageFailedException;
use Sodeker\Attachments\Domain\Exceptions\UnknownAttachmentDiskException;
use Sodeker\Attachments\Domain\Repositories\AttachmentBlobStorageInterface;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Throwable;

/**
 * Adaptador de almacenamiento sobre Flysystem: el ÚNICO archivo del módulo que sabe qué es un
 * disco.
 *
 * Sirve igual para el disco local y para S3, porque Flysystem expone la misma interfaz para
 * ambos. La aplicación consumidora debe instalar `league/flysystem-aws-s3-v3` —el paquete solo lo
 * sugiere en Composer— antes de escribir en un bucket; añadir uno nuevo es:
 *
 *   1. llenar AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION
 *   2. declarar el bucket en la fila de `tenant_disks` del cliente
 *
 * Nada más cambia. Ni el caso de uso, ni el dominio, ni los módulos que suben adjuntos.
 *
 * OJO CON LA REGIÓN: sale siempre de `AWS_DEFAULT_REGION`, nunca de la fila del cliente. Una
 * clave `region` dentro de `config` se ignora en silencio, así que hoy todos los buckets deben
 * vivir en la misma región.
 *
 * SE ESCRIBE POR STREAM, no con el contenido en memoria: con archivos de decenas de MB y varias
 * peticiones a la vez, la diferencia entre `put()` y `writeStream()` es la que decide si el
 * proceso sobrevive. Flysystem sube el stream por partes.
 */
final class FlysystemAttachmentBlobStorage implements AttachmentBlobStorageInterface
{
    public function put(AttachmentLocation $location, string $key, AttachmentBinary $binary): void
    {
        $binary->rewind();

        try {
            $written = $this->filesystem($location)->writeStream($key, $binary->stream());
        } catch (Throwable $e) {
            // El disco `public` está configurado con 'throw' => false, pero S3 puede lanzar por
            // credenciales o red. Se normaliza a la excepción del dominio para que el
            // controlador no tenga que conocer las excepciones de AWS. Se encadena la causa: sin
            // ella, un fallo de credenciales llega al log sin ninguna pista de qué pasó.
            throw AttachmentStorageFailedException::forKey($key, (string) $location, $e);
        }

        if ($written === false) {
            throw AttachmentStorageFailedException::forKey($key, (string) $location);
        }
    }

    /**
     * @return resource|null
     */
    public function readStream(AttachmentLocation $location, string $key)
    {
        $key = $this->normalizeKey($key);

        if ($key === '') {
            return null;
        }

        try {
            // `readStream()` devuelve false cuando el disco está configurado con 'throw' => false
            // —el caso del disco `public`— y lanza cuando no. Se cubren los dos y se responde
            // null en ambos: para quien lee, «no está» es la misma situación.
            $stream = $this->filesystem($location)->readStream($key);
        } catch (Throwable $e) {
            // PERO PARA QUIEN OPERA NO ES LA MISMA SITUACIÓN, Y POR ESO SE REGISTRA. El
            // controlador traduce este null a un 404 «el archivo ya no está disponible», que es
            // correcto cuando el objeto se borró y es una mentira cuando el almacenamiento
            // simplemente no contesta. Con un disco local esa diferencia casi no existía; con el
            // NAS de un cliente al otro lado de la red, una caída de minutos le dice a todo el
            // mundo que sus documentos se perdieron. El usuario sigue viendo el mismo 404 —no
            // hay nada que pueda hacer con el detalle—, pero ahora queda la pista de que fue el
            // almacenamiento y no un borrado.
            Log::warning(
                "No se pudo abrir el adjunto «{$key}» en «{$location}»: ".$e->getMessage(),
                ['exception' => $e],
            );

            return null;
        }

        return is_resource($stream) ? $stream : null;
    }

    public function delete(AttachmentLocation $location, string $key): bool
    {
        $key = $this->normalizeKey($key);

        if ($key === '' || ! $this->filesystem($location)->exists($key)) {
            return false;
        }

        return $this->filesystem($location)->delete($key);
    }

    public function exists(AttachmentLocation $location, string $key): bool
    {
        $key = $this->normalizeKey($key);

        return $key !== '' && $this->filesystem($location)->exists($key);
    }

    public function url(AttachmentLocation $location, string $key, int $expiresInMinutes = 5): ?string
    {
        $key = $this->normalizeKey($key);

        if ($key === '') {
            return null;
        }

        $filesystem = $this->filesystem($location);

        if (! $filesystem->exists($key)) {
            return null;
        }

        // Con S3 y bucket privado, `temporaryUrl()` firma un enlace con vencimiento: es el
        // camino correcto para documentos privados. El disco local no lo implementa y lanza,
        // así que ahí se cae a la URL pública del disco.
        try {
            return $filesystem->temporaryUrl($key, now()->addMinutes($expiresInMinutes));
        } catch (Throwable) {
            try {
                $url = (string) $filesystem->url($key);

                // NO TODO LO QUE DEVUELVE `url()` ES UNA URL. Para ftp y sftp, Laravel tiene un
                // caso especial (`FilesystemAdapter::getFtpUrl`) que, cuando el disco no declara
                // `url`, NO lanza: devuelve la ruta recibida tal cual. Publicar eso sería peor
                // que no devolver nada — es un href roto, y sobre todo filtra la clave interna
                // del objeto, que incluye el nombre de la base del tenant y toda la estructura
                // de carpetas. Justo lo que este módulo evita persistir y publicar.
                //
                // Se acepta solo lo que de verdad puede navegarse: con esquema (`https://…`) o
                // absoluto desde la raíz (`/storage/…`). Una clave nunca empieza por `/` —
                // `normalizeKey()` se lo quita—, así que no hay forma de confundirlas.
                return str_contains($url, '://') || str_starts_with($url, '/') ? $url : null;
            } catch (Throwable) {
                // NO HAY URL POSIBLE Y ESO NO ES UN FALLO. Un adaptador sin noción de enlace
                // —sftp, ftp, un disco local sin `url` declarada— lanza en las dos llamadas, y
                // devolver null es la respuesta correcta: ese archivo se sirve por el camino de
                // lectura controlado (`readStream`), que además es el que queremos para un
                // documento privado.
                //
                // ANTES ESTE SEGUNDO `url()` ESTABA FUERA DEL `try` y la excepción se escapaba
                // del método, contradiciendo su propio `?string`. No explotaba solo porque el
                // único llamador —`ResolvesAttachmentUrls`— la atrapa por su cuenta. Con un NAS
                // por sftp esto deja de ser el caso raro y pasa a ser lo normal.
                return null;
            }
        }
    }

    /**
     * Disco de Laravel correspondiente a esa ubicación, declarándolo si hace falta.
     *
     * POR QUÉ SE DECLARA AL VUELO: los destinos de los clientes viven en la base de datos, no en
     * `filesystems.php`, así que no existen hasta que alguien los pide. Se registra la
     * configuración sobre la plantilla del driver —que es donde están las credenciales comunes—
     * y se le sobreescribe únicamente el destino.
     *
     * ES IDEMPOTENTE Y NO NECESITA INVALIDACIÓN. El nombre del disco se deriva de la ubicación,
     * que es inmutable, así que la instancia que `Storage` memoriza bajo ese nombre siempre
     * corresponde al mismo sitio. Con nombres por cliente esto no se cumplía: `Storage` cachea
     * por nombre en un singleton de la aplicación, y reescribir la configuración no invalidaba
     * la instancia ya construida, de modo que en un proceso que atendía a varios clientes el
     * segundo escribía en el destino del primero.
     */
    private function filesystem(AttachmentLocation $location): Filesystem
    {
        $disk = $location->diskName();

        if (! is_array(config('filesystems.disks.'.$disk))) {
            Config::set('filesystems.disks.'.$disk, $this->configFor($location));
        }

        return Storage::disk($disk);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnknownAttachmentDiskException si el destino local no está declarado
     */
    private function configFor(AttachmentLocation $location): array
    {
        if ($location->driver === AttachmentLocation::DRIVER_S3) {
            /** @var array<string, mixed> $base */
            $base = (array) config('filesystems.disks.s3', ['driver' => 's3']);

            return array_merge($base, ['bucket' => $location->target]);
        }

        // Para `local` y `disk`, el destino ES un disco ya declarado en `filesystems.php`. Se
        // copia su definición tal cual para que el nombre derivado se comporte igual que el
        // original — y como se copia ENTERA, el driver que ese disco declare da igual: un `sftp`
        // contra el NAS de un cliente, un `ftp`, o un `s3` con su propio endpoint entran por
        // aquí sin que este adaptador tenga que enterarse de ninguno. Es lo que hace que agregar
        // una tecnología de almacenamiento sea declarar un disco, no tocar el módulo.
        /** @var array<string, mixed> $base */
        $base = (array) config('filesystems.disks.'.$location->target, []);

        // UN DESTINO QUE NADIE DECLARÓ ES UN ERROR, NO UN DESTINO NUEVO. Antes se improvisaba
        // aquí un disco local sobre `storage/app/<destino>`, y eso convertía un `S2` mal escrito
        // en la tabla del tenant en una carpeta creada en silencio, fuera de los respaldos y de
        // los despliegues. Ver UnknownAttachmentDiskException.
        if ($base === []) {
            Log::error(
                "Destino de adjuntos inexistente: el disco «{$location->target}» no está declarado "
                ."en config/filesystems.php (ubicación «{$location}»)."
            );

            throw UnknownAttachmentDiskException::forDisk($location->target, (string) $location);
        }

        return $base;
    }

    private function normalizeKey(string $key): string
    {
        return ltrim(trim($key), '/');
    }
}
