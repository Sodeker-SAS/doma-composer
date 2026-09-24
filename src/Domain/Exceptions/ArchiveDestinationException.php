<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

use Throwable;

/**
 * El destino de archivo no está configurado, no existe, no es seguro o rechaza las credenciales.
 * No se escribió nada, y no se reintenta: ninguno de estos casos se arregla solo.
 *
 * NO HAY DESTINO DE RESERVA, y es la diferencia principal con los adjuntos. Allí, un cliente sin
 * fila en `tenant_disks` cae al disco por defecto y el adjunto se guarda igual. Aquí, caer al
 * disco por defecto —`public`, en casi todas las aplicaciones— dejaría una copia completa de una
 * base de datos alcanzable por URL. Un destino que falta es un error de despliegue y tiene que
 * detener el proceso de forma visible.
 */
final class ArchiveDestinationException extends AttachmentException
{
    public static function notConfigured(): self
    {
        return new self(
            'No hay destino de archivo configurado: define ATTACHMENTS_ARCHIVE_DISK con el nombre de un '
            .'disco declarado en config/filesystems.php.'
        );
    }

    public static function undeclared(string $disk): self
    {
        return new self("El disco de archivo «{$disk}» no está declarado en config/filesystems.php.");
    }

    /**
     * El destino rechazó las credenciales.
     *
     * NO SE REINTENTA, y no por eficiencia: un NAS cuenta los inicios de sesión fallidos y, pasado
     * un umbral, bloquea la IP de origen (el «bloqueo automático» del Synology, o los penalizadores
     * de OpenSSH). Con tres intentos por archivo y varios archivos por corrida, una contraseña mal
     * configurada dejaría al servidor bloqueado en segundos, y ya no bastaría con corregirla.
     */
    public static function authenticationFailed(string $disk, Throwable $previous): self
    {
        return new self(
            "El destino «{$disk}» rechazó las credenciales. No se reintenta: repetir con las mismas "
            .'solo acerca el bloqueo automático de la IP en el NAS. Revisa usuario, contraseña o clave.',
            0,
            $previous,
        );
    }

    public static function publiclyServed(string $disk): self
    {
        return new self(
            "El disco «{$disk}» es de acceso público (visibilidad pública o raíz servida por el "
            .'servidor web). Un archivo de sistema no puede quedar ahí: declara un disco privado.'
        );
    }

    public function isCallerFault(): bool
    {
        return false;
    }
}
