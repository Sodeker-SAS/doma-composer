<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

use Throwable;

/**
 * No se pudo escribir el archivo en el almacenamiento.
 *
 * No es culpa de quien llama: el envío era correcto y el disco (o el bucket) falló. Se traduce
 * a 500 con mensaje genérico y el detalle queda en el log — la ruta o el nombre del bucket no
 * viajan al consumidor.
 */
final class AttachmentStorageFailedException extends AttachmentException
{
    /**
     * @param  string  $location  Destino en el que se intentó escribir: `local:public`, `s3:bucket`.
     * @param  Throwable|null  $previous  La causa real. SE ENCADENA A PROPÓSITO: sin ella, un
     *                                    fallo de credenciales de S3 llegaba al log como «no se
     *                                    pudo escribir» y sin una sola pista de por qué.
     */
    public static function forKey(string $key, string $location, ?Throwable $previous = null): self
    {
        return new self("No se pudo escribir el adjunto «{$key}» en «{$location}».", 0, $previous);
    }

    public function isCallerFault(): bool
    {
        return false;
    }
}
