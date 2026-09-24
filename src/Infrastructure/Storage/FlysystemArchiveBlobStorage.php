<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Sodeker\Attachments\Domain\Repositories\ArchiveBlobStorageInterface;
use Throwable;

/**
 * Destino de archivo sobre un disco declarado en `config/filesystems.php`.
 *
 * EL DRIVER DA IGUAL: el Synology por `sftp`, un bucket `s3` o un disco `local` entran por aquí
 * sin que esta clase distinga ninguno. Todos los adaptadores de Flysystem escriben un flujo por
 * partes, así que el tamaño del archivo no pesa en memoria, y crean las carpetas intermedias que
 * falten.
 *
 * `throw => false` Y `throw => true` SE TRATAN IGUAL. Con `throw => false`, Laravel devuelve
 * false en vez de lanzar; aquí ese false se convierte en excepción, porque un «no se pudo» que
 * pasa en silencio es justo lo que no puede ocurrir con una copia de seguridad.
 */
final class FlysystemArchiveBlobStorage implements ArchiveBlobStorageInterface
{
    /** Lo que lanza `league/flysystem-sftp-v3` cuando el servidor rechaza usuario, contraseña o clave. */
    private const SFTP_AUTHENTICATION_FAILURE = 'League\Flysystem\PhpseclibV3\UnableToAuthenticate';

    public function destination(): string
    {
        $disk = trim((string) config('attachments.archive.disk', ''));

        if ($disk === '') {
            throw ArchiveDestinationException::notConfigured();
        }

        $definition = config('filesystems.disks.'.$disk);

        if (! is_array($definition)) {
            throw ArchiveDestinationException::undeclared($disk);
        }

        if ($this->isPubliclyServed($disk, $definition)) {
            throw ArchiveDestinationException::publiclyServed($disk);
        }

        return $disk;
    }

    public function exists(string $key): bool
    {
        return $this->guard(fn (): bool => $this->filesystem()->fileExists($key));
    }

    public function size(string $key): int
    {
        return $this->guard(fn (): int => (int) $this->filesystem()->size($key));
    }

    public function write(string $key, $stream): void
    {
        $this->guard(function () use ($key, $stream): void {
            if ($this->filesystem()->writeStream($key, $stream) === false) {
                throw new RuntimeException("El destino rechazó la escritura de «{$key}».");
            }
        });
    }

    public function move(string $from, string $to): void
    {
        $this->guard(function () use ($from, $to): void {
            if ($this->filesystem()->move($from, $to) === false) {
                throw new RuntimeException("El destino no pudo renombrar «{$from}» a «{$to}».");
            }
        });
    }

    public function delete(string $key): void
    {
        try {
            $this->filesystem()->delete($key);
        } catch (Throwable) {
            // Mejor esfuerzo: ver el contrato.
        }
    }

    private function filesystem(): Filesystem
    {
        return Storage::disk($this->destination());
    }

    /**
     * Separa las credenciales rechazadas del resto de fallos, que es lo único que este adaptador
     * sabe distinguir y el caso de uso no: Flysystem las entierra bajo un genérico «Unable to check
     * existence», y el caso de uso las reintentaría como si fueran un corte de red. Ver
     * ArchiveDestinationException::authenticationFailed() para lo que cuesta reintentarlas.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            if ($this->isAuthenticationFailure($e)) {
                throw ArchiveDestinationException::authenticationFailed($this->destination(), $e);
            }

            throw $e;
        }
    }

    /**
     * Se compara por nombre de clase y no con `instanceof` contra una clase importada porque el
     * adaptador SFTP es opcional: el paquete no lo exige y en una aplicación sin él la clase no
     * existe.
     */
    private function isAuthenticationFailure(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (is_a($cause, self::SFTP_AUTHENTICATION_FAILURE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Lo que se escriba en este disco quedaría alcanzable por URL?
     *
     * Se comprueba lo que se puede comprobar sin conectarse: el disco `public` por nombre, una
     * visibilidad pública declarada y, para discos locales, una raíz dentro de lo que sirve el
     * servidor web. No cubre un bucket con política pública configurada fuera de Laravel; eso lo
     * debe impedir quien administra el bucket.
     *
     * @param  array<string, mixed>  $definition
     */
    private function isPubliclyServed(string $disk, array $definition): bool
    {
        if ($disk === 'public' || ($definition['visibility'] ?? null) === 'public') {
            return true;
        }

        if (($definition['driver'] ?? null) !== 'local') {
            return false;
        }

        $root = $this->normalizePath((string) ($definition['root'] ?? ''));

        foreach ([public_path(), storage_path('app/public')] as $served) {
            if (str_starts_with($root.'/', $this->normalizePath($served).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Con `realpath` cuando la carpeta existe, para que un enlace simbólico o un `..` no disfracen
     * una raíz pública.
     */
    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }
}
