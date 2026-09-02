<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Construye la clave con la que se guarda un adjunto:
 *
 *     {app}/{tenant}/{módulo}/{entidad}/{id}[/{ámbito}]/{ulid}.{ext}
 *     app/acme/studies/study/42/documents/01K7XV….pdf
 *
 * DOS SEGMENTOS QUE NO ESTABAN Y SÍ IMPORTAN:
 *
 * 1. `{app}` — prefijo propio de la aplicación. Separa espacios cuando varias aplicaciones
 *    comparten el mismo almacenamiento.
 *
 * 2. `{tenant}` — la versión anterior no lo incluía, así que dos clientes distintos con el
 *    mismo `study_id` escribían en la misma carpeta. Con nombres ULID no había colisión de
 *    archivos, pero sí mezcla de clientes bajo un mismo prefijo, lo que impide aplicar
 *    políticas de acceso o de retención por tenant cuando esto viva en un bucket.
 *
 * EL NOMBRE DEL ARCHIVO NO ES EL DEL USUARIO: se guarda con un ULID y la extensión ya
 * verificada contra el contenido. El nombre original viaja aparte, en la base de datos. Así el
 * nombre que eligió un tercero nunca llega a formar parte de una ruta.
 */
final readonly class AttachmentStoragePath
{
    private function __construct(
        public string $key,
    ) {}

    /**
     * @param  string  $appPrefix  Aplicación dueña del prefijo raíz ("app").
     * @param  string  $tenant  Identificador del cliente, ya normalizado.
     * @param  string  $fileName  Nombre físico del objeto, incluida la extensión.
     */
    public static function build(
        string $appPrefix,
        string $tenant,
        AttachmentOwner $owner,
        string $fileName,
    ): self {
        self::assertSegment($appPrefix, 'la aplicación');
        self::assertSegment($tenant, 'el tenant');

        if (! preg_match('/^[0-9A-Za-z]+\.[a-z0-9]+$/', $fileName)) {
            throw new InvalidArgumentException('El nombre físico del adjunto es inválido.');
        }

        $segments = [
            $appPrefix,
            $tenant,
            $owner->module,
            $owner->entity,
            (string) $owner->entityId,
        ];

        if ($owner->scope !== null) {
            $segments[] = $owner->scope;
        }

        $segments[] = $fileName;

        return new self(implode('/', $segments));
    }

    /**
     * Alfabeto restringido: sin puntos ni barras no hay forma de escapar del prefijo con un
     * `../`, venga el valor de donde venga.
     */
    private static function assertSegment(string $value, string $label): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException("El identificador de {$label} para almacenar el adjunto es inválido.");
        }
    }
}
