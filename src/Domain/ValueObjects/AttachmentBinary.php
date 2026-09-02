<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use InvalidArgumentException;
use RuntimeException;

/**
 * El archivo que entra al módulo, expresado sin framework.
 *
 * POR QUÉ EXISTE: el puerto de almacenamiento recibía un `Illuminate\Http\UploadedFile`, es
 * decir, "un archivo que llegó por un formulario HTTP". Eso ata el contrato a un origen
 * concreto y obliga a fabricar un `UploadedFile` falso para cualquier otra procedencia. Con
 * este objeto, un adjunto es lo que realmente es —un flujo de bytes con nombre y tamaño— y da
 * igual si vino de un multipart, de una cadena base64, de un job en cola o de un comando de
 * consola. Los tres orígenes previstos (web de Fintegra, F16 y Certus) desembocan aquí.
 *
 * POR QUÉ GUARDA UN STREAM Y NO EL CONTENIDO: un value object normalmente es inmutable y sin
 * estado, y un recurso abierto no lo es. Se acepta la excepción a conciencia: cargar el archivo
 * completo en memoria pondría un tope de tamaño artificial y multiplicaría el consumo de RAM
 * por cada petición concurrente. El stream se pasa tal cual hasta Flysystem, que lo sube por
 * partes; con S3 esa diferencia deja de ser teórica.
 */
final class AttachmentBinary
{
    /**
     * @param  resource  $stream
     */
    private function __construct(
        private $stream,
        public readonly string $originalName,
        public readonly int $sizeBytes,
    ) {}

    /**
     * @param  resource  $stream  Flujo abierto en lectura y posicionado al inicio.
     * @param  string  $originalName  Nombre con el que el usuario conoce el archivo.
     * @param  int  $sizeBytes  Tamaño real en bytes.
     */
    public static function fromStream(mixed $stream, string $originalName, int $sizeBytes): self
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('El contenido del adjunto no es un flujo válido.');
        }

        $originalName = trim($originalName);
        if ($originalName === '') {
            throw new InvalidArgumentException('El adjunto debe tener un nombre.');
        }

        if ($sizeBytes <= 0) {
            throw new InvalidArgumentException('El adjunto está vacío.');
        }

        return new self($stream, $originalName, $sizeBytes);
    }

    /**
     * Extensión declarada en el nombre del archivo, en minúsculas y sin punto.
     *
     * Es solo una DECLARACIÓN del cliente: no se usa para decidir el tipo, sino para contrastar
     * contra lo que digan los bytes. Ver {@see AttachmentContentType::resolve()}.
     */
    public function declaredExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * Primeros `$length` bytes del archivo, dejando el flujo de nuevo al inicio para que quien
     * lo almacene después lo lea completo.
     *
     * CUÁNTO PEDIR NO LO DECIDE ESTA CLASE: con los magic bytes bastan ocho, pero distinguir un
     * docx de un xlsx exige leer bastante más, porque los dos empiezan igual y solo se separan
     * por lo que llevan dentro. Quien pregunta sabe cuánto necesita.
     *
     * EXIGE UN FLUJO REBOBINABLE Y FALLA SI NO LO ES, en vez de seguir adelante: lo que se lee
     * aquí ya no lo lee el almacenamiento, así que sobre un flujo que no admite rebobinado el
     * archivo se guardaría sin su cabecera —y en silencio, porque nadie lo nota hasta intentar
     * abrirlo—. Hoy todos los orígenes son temporales en disco y rebobinan; la guardia está para
     * el día que alguien enchufe aquí un `php://input`.
     */
    public function head(int $length): string
    {
        if (! $this->isSeekable()) {
            throw new RuntimeException(
                'El adjunto llega en un flujo que no admite rebobinado: no puede inspeccionarse sin corromperlo.'
            );
        }

        $head = (string) fread($this->stream, max(1, $length));
        $this->rewind();

        return $head;
    }

    /** @return resource */
    public function stream(): mixed
    {
        return $this->stream;
    }

    public function rewind(): void
    {
        // Tolerante a propósito: quien de verdad no puede seguir sin rebobinar es `head()`, y
        // allí sí se corta. Aquí un flujo ya consumido no siempre es un error.
        if ($this->isSeekable()) {
            rewind($this->stream);
        }
    }

    private function isSeekable(): bool
    {
        return is_resource($this->stream) && (stream_get_meta_data($this->stream)['seekable'] ?? false);
    }

    /**
     * Libera el descriptor. Es idempotente: Flysystem cierra el stream que le entregan, así que
     * quien lo abrió no puede saber si sigue vivo — de ahí la comprobación.
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
