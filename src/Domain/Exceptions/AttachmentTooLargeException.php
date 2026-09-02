<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

/**
 * El archivo supera el tamaño máximo por adjunto.
 *
 * OJO AL OTRO LÍMITE: nginx corta el cuerpo completo de la petición antes que esto
 * (`client_max_body_size`), y devuelve un 413 en HTML que nunca llega a Laravel. Este error
 * cubre el archivo individual; el del cuerpo total se ajusta en la configuración del servidor.
 */
final class AttachmentTooLargeException extends AttachmentException
{
    public static function forFile(string $fileName, int $sizeBytes, int $maxBytes): self
    {
        $size = round($sizeBytes / 1048576, 2);
        $max = round($maxBytes / 1048576, 2);

        return new self(
            "El archivo «{$fileName}» pesa {$size} MB y supera el máximo permitido de {$max} MB."
        );
    }

    public function isCallerFault(): bool
    {
        return true;
    }
}
