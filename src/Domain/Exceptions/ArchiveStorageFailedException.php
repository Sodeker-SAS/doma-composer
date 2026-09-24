<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

use Throwable;

/**
 * La subida al destino de archivo falló después de agotar los intentos.
 *
 * No es culpa de quien llama: el archivo y la clave eran correctos y lo que falló fue el
 * transporte —red, credenciales, disco lleno en el NAS—. El temporal `.part` de cada intento ya
 * se intentó borrar, y el nombre final nunca llegó a existir.
 */
final class ArchiveStorageFailedException extends AttachmentException
{
    /**
     * @param  Throwable|null  $previous  La causa real, encadenada para que llegue al log.
     */
    public static function forKey(string $key, string $destination, ?Throwable $previous = null): self
    {
        return new self("No se pudo archivar «{$key}» en «{$destination}»".self::causes($previous), 0, $previous);
    }

    /**
     * El destino aceptó la escritura pero no quedó el archivo completo. Pasa cuando se corta la
     * conexión a mitad o el NAS se queda sin espacio y no lo reporta como error.
     */
    public static function incomplete(string $key, string $destination, int $expectedBytes, int $writtenBytes): self
    {
        return new self(
            "La subida de «{$key}» a «{$destination}» quedó incompleta: se esperaban {$expectedBytes} "
            ."bytes y el destino tiene {$writtenBytes}."
        );
    }

    public function isCallerFault(): bool
    {
        return false;
    }

    /**
     * Toda la cadena de causas en una línea.
     *
     * Flysystem envuelve el error real en uno genérico: unas credenciales rechazadas o un NAS
     * apagado llegan como «Unable to check existence for: …», y lo que de verdad pasó queda dos
     * niveles más abajo. Un log que guarde solo el mensaje se quedaría con lo genérico, así que
     * se recorre la cadena entera.
     */
    private static function causes(?Throwable $previous): string
    {
        $messages = [];

        for ($e = $previous; $e !== null; $e = $e->getPrevious()) {
            $message = trim($e->getMessage());

            if ($message !== '' && ! in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        return $messages === [] ? '' : ': '.implode(' ← ', $messages);
    }
}
