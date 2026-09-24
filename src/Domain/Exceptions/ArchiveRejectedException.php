<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

/**
 * El archivo o la clave que se pidió archivar no son aceptables. No se escribió nada.
 *
 * ES CULPA DE QUIEN LLAMA: corrigiendo la clave, el tipo o el archivo, el mismo envío pasa. Por
 * eso reintentar sin cambiar nada no tiene sentido, y el servicio no lo hace.
 */
final class ArchiveRejectedException extends AttachmentException
{
    public static function invalidKey(string $key, string $reason): self
    {
        return new self("La clave de archivo «{$key}» no es válida: {$reason}.");
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unsupportedType(string $key, string $extension, array $allowed): self
    {
        $list = $allowed === [] ? 'ninguno' : implode(', ', $allowed);

        return new self("El tipo «{$extension}» de «{$key}» no se archiva. Tipos admitidos: {$list}.");
    }

    public static function blocked(string $key, string $extension): self
    {
        return new self("La extensión «{$extension}» de «{$key}» está bloqueada por seguridad y no se archiva nunca.");
    }

    public static function unreadableFile(string $localPath): self
    {
        return new self("El archivo local «{$localPath}» no existe, no es un archivo regular o no se puede leer.");
    }

    public static function emptyFile(string $localPath): self
    {
        return new self("El archivo local «{$localPath}» está vacío.");
    }

    public static function signatureMismatch(string $localPath, string $extension): self
    {
        return new self(
            "El archivo local «{$localPath}» no empieza por la firma de un «{$extension}»: "
            .'está incompleto, dañado o es de otro formato.'
        );
    }

    /**
     * Lo archivado no se sobrescribe. Si llega otro contenido con la misma clave, la clave está
     * mal construida —dos archivos distintos no pueden llamarse igual— y pisar el anterior
     * destruiría una copia que se suponía permanente.
     */
    public static function alreadyExists(string $key, string $destination, int $localBytes, int $remoteBytes): self
    {
        return new self(
            "Ya existe «{$key}» en «{$destination}» con otro contenido ({$remoteBytes} bytes frente a "
            ."{$localBytes} del archivo local). Lo archivado no se sobrescribe."
        );
    }

    public function isCallerFault(): bool
    {
        return true;
    }
}
