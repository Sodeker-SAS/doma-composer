<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Http;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;

/**
 * Traduce un archivo recibido por HTTP al objeto neutro que entiende el módulo.
 *
 * POR QUÉ VIVE EN Infrastructure/Http: es exactamente la costura entre «cómo llegó» y «qué es»,
 * el mismo papel que cumple `TranslatesApiPayloadKeys` con las claves del cuerpo. El día que
 * haya que aceptar base64, será otra fábrica al lado de esta —no otro caso de uso—.
 *
 * NO CARGA EL ARCHIVO EN MEMORIA: PHP ya escribió el multipart en un temporal del disco, así
 * que aquí solo se abre un flujo sobre él. Es lo que permite que un archivo de decenas de MB
 * no dependa de `memory_limit`.
 */
final class UploadedFileAttachmentFactory
{
    public function fromUploadedFile(UploadedFile $file): AttachmentBinary
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_readable($realPath)) {
            throw new RuntimeException('El archivo recibido no pudo leerse del almacenamiento temporal.');
        }

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('El archivo recibido no pudo abrirse para su lectura.');
        }

        return AttachmentBinary::fromStream(
            stream: $stream,
            originalName: $this->safeOriginalName($file),
            sizeBytes: (int) $file->getSize(),
        );
    }

    /**
     * El nombre original lo escribe quien llama y termina guardado en base de datos y devuelto
     * en las respuestas. No forma parte de ninguna ruta —el archivo físico se llama con un
     * ULID—, pero aun así se sanea: `basename` descarta cualquier intento de ruta, se quitan
     * los caracteres de control y se limita el largo.
     */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(trim($file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'adjunto';
        }

        return mb_substr($name, 0, 255);
    }
}
