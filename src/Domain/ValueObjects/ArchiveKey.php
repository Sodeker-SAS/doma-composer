<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use Sodeker\Attachments\Domain\Exceptions\ArchiveRejectedException;

/**
 * Clave con la que se guarda un archivo de sistema, relativa a la raíz del destino:
 *
 *     backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump
 *
 * A DIFERENCIA DE {@see AttachmentStoragePath}, LA CLAVE LA DECIDE QUIEN LLAMA y se respeta tal
 * cual. Ahí el nombre venía de un tercero y por eso se sustituía por un ULID; aquí lo construye la
 * propia aplicación y es justo la información que tiene que sobrevivir: quien administra el
 * destino identifica cada archivo por su ruta, sin consultar ninguna base de datos.
 *
 * QUE SE RESPETE NO SIGNIFICA QUE SE ACEPTE CUALQUIER COSA. Cada segmento se limita a un alfabeto
 * que no puede fabricar un `..`, una ruta absoluta ni un separador de Windows, así que ningún
 * valor puede escribir fuera de la raíz del disco.
 */
final readonly class ArchiveKey
{
    /**
     * Empieza por letra o dígito: eso descarta `.`, `..` y los archivos ocultos sin necesidad de
     * listarlos. Después admite punto, guion y guion bajo, que es lo que usan fechas y horas.
     */
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const MAX_LENGTH = 1024;

    private function __construct(
        public string $value,
        /** Extensión en minúsculas y sin punto: `dump`. */
        public string $extension,
    ) {}

    /**
     * @throws ArchiveRejectedException si la clave no es una ruta relativa segura con extensión
     */
    public static function fromString(string $key): self
    {
        // No se recorta: una clave con espacios alrededor delata un error de quien la construyó,
        // y corregirlo en silencio guardaría el archivo con un nombre que nadie pidió.
        if ($key === '') {
            throw ArchiveRejectedException::invalidKey($key, 'está vacía');
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw ArchiveRejectedException::invalidKey($key, 'supera '.self::MAX_LENGTH.' caracteres');
        }

        foreach (explode('/', $key) as $segment) {
            if (! preg_match(self::SEGMENT, $segment)) {
                throw ArchiveRejectedException::invalidKey(
                    $key,
                    'cada segmento debe empezar por letra o dígito y usar solo letras, dígitos, punto, guion o guion bajo'
                );
            }
        }

        $fileName = basename($key);
        $dot = strrpos($fileName, '.');
        $extension = $dot === false ? '' : strtolower(substr($fileName, $dot + 1));

        if ($extension === '') {
            throw ArchiveRejectedException::invalidKey($key, 'el archivo no tiene extensión');
        }

        return new self($key, $extension);
    }
}
