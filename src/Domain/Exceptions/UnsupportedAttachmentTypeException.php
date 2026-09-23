<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

/**
 * El contenido del archivo no corresponde a un formato aceptado, o contradice la extensión con
 * la que se envió.
 */
final class UnsupportedAttachmentTypeException extends AttachmentException
{
    public static function unrecognized(string $fileName, string $allowed): self
    {
        return new self(
            "El archivo «{$fileName}» no es de un formato permitido. Se aceptan: {$allowed}."
        );
    }

    /**
     * El formato que declara SÍ se acepta, pero su contenido no se pudo reconocer como tal.
     *
     * POR QUÉ MERECE SU PROPIO MENSAJE: responder «no es de un formato permitido. Se aceptan:
     * PDF…» a quien acaba de enviar un PDF es desconcertante y no dice qué hacer. Este caso es
     * distinto del anterior —el formato no está en discusión, lo que falla es el contenido— y
     * el mensaje debe apuntar a lo único que puede revisar quien envía: el archivo en sí.
     */
    /**
     * Extensión de la lista de bloqueo. El mensaje NO enumera lo permitido a propósito: no es un
     * problema de «elige otro formato de la lista», es que ese formato no se acepta nunca.
     */
    public static function blocked(string $fileName, string $declared): self
    {
        return new self(
            "El archivo «{$fileName}» tiene una extensión que no se admite por seguridad "
            .'('.strtoupper($declared).'), sea cual sea su contenido.'
        );
    }

    public static function unreadable(string $fileName, string $declared): self
    {
        $formato = strtoupper($declared);

        return new self(
            "El archivo «{$fileName}» se envió como {$formato}, pero su contenido no se reconoce "
            ."como tal: puede estar dañado, incompleto o no ser realmente un {$formato}."
        );
    }

    /**
     * El caso interesante: la extensión dice una cosa y los bytes dicen otra. Se nombra el
     * formato detectado para que un error honesto de nombrado se corrija en un minuto, sin que
     * el mensaje sirva de guía a quien esté probando el límite a propósito.
     */
    public static function mismatch(string $fileName, string $declared, string $detected): self
    {
        return new self(
            "El archivo «{$fileName}» se envió como {$declared} pero su contenido es {$detected}."
        );
    }

    public function isCallerFault(): bool
    {
        return true;
    }
}
