<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Application\Services;

use Illuminate\Support\Sleep;
use Sodeker\Attachments\Contracts\ArchiveStoragePort;
use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveRejectedException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveStorageFailedException;
use Sodeker\Attachments\Domain\Repositories\ArchiveBlobStorageInterface;
use Sodeker\Attachments\Domain\ValueObjects\ArchivedFile;
use Sodeker\Attachments\Domain\ValueObjects\ArchiveKey;
use Throwable;

/**
 * Caso de uso: archivar un archivo de sistema en el destino configurado.
 *
 * EL ORDEN IMPORTA. Todo lo que puede rechazar el envío —clave, tipo, archivo local, firma,
 * destino— se comprueba ANTES de abrir una conexión o escribir un byte, y en ese caso no se
 * reintenta: el mismo envío fallaría igual. Solo el transporte se reintenta. Las credenciales
 * rechazadas tampoco: llegan como ArchiveDestinationException y cortan en el primer intento.
 *
 * CADA INTENTO SUBE A UN TEMPORAL PROPIO (`<clave>.<aleatorio>.part`) y lo renombra al nombre
 * final únicamente cuando el tamaño remoto coincide con el local. Quien revise el destino a mitad
 * de una subida ve un `.part`, nunca un archivo con nombre definitivo y contenido a medias. El
 * sufijo aleatorio evita que dos intentos, o dos procesos, compartan temporal.
 */
final class ArchiveFileService implements ArchiveStoragePort
{
    public function __construct(
        private readonly ArchiveBlobStorageInterface $storage,
    ) {}

    public function store(string $localPath, string $key): ArchivedFile
    {
        $archiveKey = ArchiveKey::fromString($key);

        $this->assertTypeIsArchivable($archiveKey);
        $sizeBytes = $this->assertReadableFile($localPath);
        $this->assertSignature($localPath, $archiveKey);

        $destination = $this->storage->destination();
        $sha256 = (string) hash_file('sha256', $localPath);

        $maxAttempts = max(1, (int) config('attachments.archive.attempts', 3));
        $delaySeconds = max(0, (int) config('attachments.archive.retry_delay_seconds', 10));

        for ($attempt = 1; ; $attempt++) {
            try {
                // LA CONSULTA DE EXISTENCIA VA DENTRO DEL INTENTO, no antes: es la primera vez que
                // se habla con el destino, y un NAS que no contesta tiene que reintentarse igual
                // que una escritura cortada. Además, si un intento anterior llegó a renombrar
                // pero se perdió la respuesta, este lo encuentra ya archivado en vez de fallar.
                if ($this->alreadyArchived($archiveKey->value, $sizeBytes, $destination)) {
                    return new ArchivedFile($archiveKey->value, $destination, $sizeBytes, $sha256, $attempt - 1, true);
                }

                $this->upload($localPath, $archiveKey->value, $sizeBytes, $destination);

                return new ArchivedFile($archiveKey->value, $destination, $sizeBytes, $sha256, $attempt, false);
            } catch (ArchiveStorageFailedException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                if ($delaySeconds > 0) {
                    Sleep::for($delaySeconds)->seconds();
                }
            }
        }
    }

    /**
     * Idempotencia: la misma clave con el mismo tamaño ya está archivada, y otra vez no hace falta
     * subir varios cientos de megas. Con otro tamaño es un conflicto, y ese NO se reintenta.
     *
     * @throws ArchiveRejectedException si la clave ya existe con otro contenido
     * @throws ArchiveStorageFailedException si el destino no responde
     */
    private function alreadyArchived(string $key, int $sizeBytes, string $destination): bool
    {
        try {
            if (! $this->storage->exists($key)) {
                return false;
            }

            $remoteBytes = $this->storage->size($key);
        } catch (ArchiveDestinationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ArchiveStorageFailedException::forKey($key, $destination, $e);
        }

        if ($remoteBytes !== $sizeBytes) {
            throw ArchiveRejectedException::alreadyExists($key, $destination, $sizeBytes, $remoteBytes);
        }

        return true;
    }

    /**
     * @throws ArchiveStorageFailedException
     */
    private function upload(string $localPath, string $key, int $sizeBytes, string $destination): void
    {
        $partial = $key.'.'.bin2hex(random_bytes(4)).'.part';
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw ArchiveStorageFailedException::forKey($key, $destination);
        }

        try {
            $this->storage->write($partial, $stream);

            $writtenBytes = $this->storage->size($partial);

            if ($writtenBytes !== $sizeBytes) {
                throw ArchiveStorageFailedException::incomplete($key, $destination, $sizeBytes, $writtenBytes);
            }

            $this->storage->move($partial, $key);
        } catch (ArchiveStorageFailedException|ArchiveDestinationException $e) {
            $this->storage->delete($partial);

            throw $e;
        } catch (Throwable $e) {
            $this->storage->delete($partial);

            throw ArchiveStorageFailedException::forKey($key, $destination, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Lista cerrada: solo se archiva lo que `attachments.archive.types` declara. Y las extensiones
     * bloqueadas del módulo ganan siempre, aunque alguien las añada a esa lista por error.
     */
    private function assertTypeIsArchivable(ArchiveKey $key): void
    {
        $blocked = array_map('strtolower', (array) config('attachments.blocked_extensions', []));

        if (in_array($key->extension, $blocked, true)) {
            throw ArchiveRejectedException::blocked($key->value, $key->extension);
        }

        $types = $this->archivableTypes();

        if (! array_key_exists($key->extension, $types)) {
            throw ArchiveRejectedException::unsupportedType($key->value, $key->extension, array_keys($types));
        }
    }

    private function assertReadableFile(string $localPath): int
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw ArchiveRejectedException::unreadableFile($localPath);
        }

        $sizeBytes = (int) filesize($localPath);

        if ($sizeBytes <= 0) {
            throw ArchiveRejectedException::emptyFile($localPath);
        }

        return $sizeBytes;
    }

    /**
     * El archivo es de la propia aplicación, así que esto no es una defensa contra terceros sino
     * un control de calidad: un dump cortado a la mitad por falta de espacio, o un archivo de
     * texto con el mensaje de error de `pg_dump`, no llega al destino con apariencia de copia
     * válida.
     */
    private function assertSignature(string $localPath, ArchiveKey $key): void
    {
        $signature = $this->archivableTypes()[$key->extension] ?? null;

        if ($signature === null || $signature === '') {
            return;
        }

        $handle = @fopen($localPath, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, strlen($signature));

        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($head !== $signature) {
            throw ArchiveRejectedException::signatureMismatch($localPath, $key->extension);
        }
    }

    /**
     * @return array<string, string|null> extensión en minúsculas => firma inicial, o null si no tiene
     */
    private function archivableTypes(): array
    {
        $types = [];

        foreach ((array) config('attachments.archive.types', []) as $extension => $signature) {
            $types[strtolower((string) $extension)] = $signature === null ? null : (string) $signature;
        }

        return $types;
    }
}
