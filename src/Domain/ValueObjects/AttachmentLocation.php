<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Dónde está FÍSICAMENTE un adjunto, expresado de forma que no dependa de ninguna
 * configuración que pueda cambiar después.
 *
 * POR QUÉ EXISTE: antes lo único que se guardaba del destino era el nombre de un disco de
 * Laravel, y ese nombre era una etiqueta que se re-vinculaba. El disco propio de un cliente se
 * registraba al vuelo leyendo `tenant_disks`, así que el día que se cambiara esa fila —de disco
 * local a un bucket, por ejemplo— TODOS los adjuntos anteriores de ese cliente pasaban a
 * buscarse en el destino nuevo, y los que seguían físicamente en el servidor dejaban de
 * encontrarse. La única salida era migrar los objetos cada vez que se tocaba un destino.
 *
 * Este objeto guarda la ubicación real y no una referencia a la configuración vigente, de modo
 * que cambiar dónde escribe un cliente afecta solo a lo que se escriba DESPUÉS. Si algún día se
 * mueven los objetos de verdad, se actualiza la columna `storage_location` de las filas movidas
 * y nada más.
 *
 * FORMATO: `driver:destino`, legible y fácil de actualizar en bloque.
 *
 *     local:public                  el disco `public` declarado en filesystems.php
 *     s3:app-acme-adjuntos          ese bucket
 *     disk:nas                      el disco `nas` de filesystems.php, con el driver que declare
 *
 * PARA `local` Y `disk` SE APUNTA AL NOMBRE DEL DISCO, NO A UNA RUTA ABSOLUTA: una ruta absoluta se
 * rompe entre entornos —dentro del contenedor es `/var/www/...` y fuera otra cosa—, mientras que
 * `public` está declarado en código y no se re-vincula nunca.
 *
 * NUNCA GUARDA CREDENCIALES. Dice en qué bucket, no con qué llaves: esas salen de la definición
 * del driver en `filesystems.php`, que las lee del entorno.
 */
final readonly class AttachmentLocation
{
    /** Disco declarado en `filesystems.php`; el destino es su nombre. */
    public const DRIVER_LOCAL = 'local';

    /** Bucket de S3; el destino es el nombre del bucket. */
    public const DRIVER_S3 = 's3';

    /**
     * Disco declarado en `filesystems.php` que NO es local: un NAS por SFTP, un FTP, un
     * almacenamiento compatible con S3 que tiene su propio endpoint. El destino es el NOMBRE del
     * disco, igual que en `local`.
     *
     * POR QUÉ NO SE REUSÓ `local` PARA ESTO: la rama de `FlysystemAttachmentBlobStorage` que
     * atiende a `local` ya copiaba la definición del disco tal cual, así que un disco `sftp`
     * habría funcionado describiéndose como `local:nas`. Y esa es exactamente la razón de no
     * hacerlo: `storage_location` se persiste para siempre y su único trabajo es decir la verdad
     * sobre dónde quedó el archivo. Un `local:` sobre un archivo que vive en un NAS por red deja
     * la columna inservible justo para lo que existe —migrar, auditar, saber a quién llamar
     * cuando no responde—.
     *
     * POR QUÉ ES UN TOKEN GENÉRICO Y NO `sftp`, `nas` O `synology`: el protocolo ya está
     * declarado en `filesystems.php`, que es donde viven además las credenciales. Repetirlo aquí
     * obligaría a ampliar esta lista blanca cada vez que aparece una tecnología nueva, y a
     * arriesgar que la fila del tenant y el disco declarado dijeran cosas distintas. `disk`
     * significa «lo que ese disco declare», y con eso el módulo no vuelve a tocarse.
     */
    public const DRIVER_DISK = 'disk';

    /**
     * Lista blanca. Un driver que no esté aquí se rechaza al construir, porque el valor acaba
     * decidiendo con qué adaptador se abre el archivo.
     */
    private const DRIVERS = [self::DRIVER_LOCAL, self::DRIVER_S3, self::DRIVER_DISK];

    private function __construct(
        public string $driver,
        public string $target,
    ) {}

    public static function of(string $driver, string $target): self
    {
        $driver = strtolower(trim($driver));
        $target = trim($target);

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(
                "El almacenamiento «{$driver}» no está soportado. Se aceptan: ".implode(', ', self::DRIVERS).'.'
            );
        }

        // Cubre a la vez nombres de disco (`public`) y de bucket (`app-acme-adjuntos`).
        // Sin barras ni dos puntos: ni se puede escapar de la ruta ni romper el formato.
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $target)) {
            throw new InvalidArgumentException('El destino de almacenamiento del adjunto es inválido.');
        }

        return new self($driver, $target);
    }

    /** Disco local por defecto de la aplicación, para los adjuntos anteriores a esta columna. */
    public static function localDisk(string $disk): self
    {
        return self::of(self::DRIVER_LOCAL, $disk);
    }

    /**
     * Disco declarado en `filesystems.php` cuyo driver NO es local: el NAS de un cliente por
     * SFTP, un FTP, un almacenamiento compatible con S3 con su propio endpoint.
     *
     * El destino es el nombre del disco y nada más. Las credenciales y el protocolo viven en su
     * definición, que las lee del entorno — ver {@see self::DRIVER_DISK}.
     */
    public static function declaredDisk(string $disk): self
    {
        return self::of(self::DRIVER_DISK, $disk);
    }

    /**
     * Lee el valor tal como está en `attachments.storage_location`.
     *
     * Una fila vacía significa «anterior a que existiera la columna», y todo lo que se escribió
     * antes vive en el disco por defecto: por eso quien llama pasa ese disco como respaldo en
     * lugar de que este objeto adivine.
     */
    public static function fromString(?string $value, string $fallbackLocalDisk): self
    {
        $value = trim((string) $value);

        if ($value === '') {
            return self::localDisk($fallbackLocalDisk);
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("La ubicación de almacenamiento «{$value}» no tiene el formato «driver:destino».");
        }

        return self::of($parts[0], $parts[1]);
    }

    /**
     * Nombre con el que se registra este destino en `filesystems.disks`.
     *
     * SE DERIVA DE LA UBICACIÓN Y NO DEL CLIENTE, y eso resuelve tres cosas a la vez:
     *
     *   1. No se re-vincula. La ubicación es inmutable, así que el nombre que produce también.
     *   2. Desaparece la colisión en la caché de `Storage`. El `FilesystemManager` memoriza las
     *      instancias por nombre y es singleton de la aplicación; con nombres por cliente había
     *      que invalidarlos a mano, y con nombres por ubicación dos clientes en el mismo bucket
     *      comparten instancia —que es lo correcto— y en buckets distintos no se pisan.
     *   3. La lectura no necesita consultar `landlord`: el nombre sale de la propia fila.
     *
     * Se sanea a un alfabeto seguro porque se usa como clave de configuración: un punto o un
     * guion partirían `filesystems.disks.<nombre>` en varios niveles del árbol de `config`.
     *
     * NO SE ACOTA SU LARGO: este valor ya no se persiste en ninguna columna —vive solo como
     * clave de `filesystems.disks` durante la petición—, así que puede ser tan largo como el
     * destino lo pida. Recortarlo sería además peligroso: dos buckets que compartan prefijo
     * acabarían con el mismo nombre y volverían a compartir instancia en la caché de `Storage`.
     */
    public function diskName(): string
    {
        return 'att_'.$this->driver.'_'.(string) preg_replace('/[^a-z0-9]+/', '_', $this->target);
    }

    public function equals(self $other): bool
    {
        return $this->driver === $other->driver && $this->target === $other->target;
    }

    public function __toString(): string
    {
        return $this->driver.':'.$this->target;
    }
}
