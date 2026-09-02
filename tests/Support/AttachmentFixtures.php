<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use Sodeker\Attachments\Domain\ValueObjects\AllowedAttachmentTypes;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;

/**
 * Adjuntos de juguete para las pruebas del módulo.
 *
 * POR QUÉ AQUÍ Y NO EN `tests/Pest.php`: las firmas binarias las necesitan varios archivos de
 * prueba, pero no tienen por qué convertirse en funciones globales de toda la suite.
 */
final class AttachmentFixtures
{
    /**
     * Primeros bytes reales de cada formato. Son los mismos que declara
     * `config/attachments.php`; se repiten aquí a propósito para que una prueba falle si
     * alguien cambia la configuración sin querer.
     */
    public const PDF = '%PDF-1.7';

    public const PNG = "\x89PNG\r\n\x1A\n";

    public const JPG = "\xFF\xD8\xFF\xE0";

    /** Contenedor OLE2 del Office antiguo, que es lo que hay detrás de un .xls. */
    public const XLS = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Cabecera de un ejecutable de Windows: el caso que el módulo existe para rechazar. */
    public const EXE = "MZ\x90\x00\x03\x00\x00\x00";

    /**
     * Un ZIP de verdad que contiene la entrada indicada.
     *
     * SE CONSTRUYE DE VERDAD Y NO SE FALSIFICA porque lo que se está probando es justamente que
     * el módulo mire dentro del contenedor: un `"PK\x03\x04"` a pelo no distinguiría un docx de
     * un xlsx, que es el fallo que estas pruebas cubren.
     *
     * Con `word/document.xml` sale un docx, con `xl/workbook.xml` un xlsx, con
     * `ppt/presentation.xml` un pptx, y con cualquier otra cosa un ZIP corriente que el módulo
     * debe rechazar.
     */
    public static function ooxml(string $part): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ooxml');

        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($part, '<contenido/>');
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Flujo en memoria con el contenido exacto que se le pase.
     *
     * Se devuelve el recurso desnudo para que la prueba pueda comprobar desde fuera si quedó
     * cerrado, que es justo lo que no se puede preguntarle al value object.
     *
     * @return resource
     */
    public static function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new \RuntimeException('No se pudo abrir el flujo en memoria de la prueba.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /** Atajo para las pruebas a las que no les importa el recurso. */
    public static function of(string $contents, string $originalName): AttachmentBinary
    {
        return AttachmentBinary::fromStream(
            self::stream($contents),
            $originalName,
            strlen($contents),
        );
    }

    /**
     * Política de tipos reducida. Se declara aquí en lugar de leer `config()` para que las
     * pruebas del dominio no necesiten arrancar el framework.
     *
     * OJO A LA DIFERENCIA con las constantes de arriba: aquí van las FIRMAS —el mínimo que
     * identifica al formato—, mientras que las constantes son CONTENIDO de ejemplo, que empieza
     * por la firma y sigue con lo que venga. Un JPEG real puede seguir con `\xE0` o con `\xDB`,
     * y por eso su firma son solo tres bytes.
     */
    public static function allowed(): AllowedAttachmentTypes
    {
        return AllowedAttachmentTypes::fromMap(
            [
                'pdf' => '%PDF',
                'jpg' => "\xFF\xD8\xFF",
                'png' => "\x89PNG\r\n\x1A\n",
                'xls' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
                // Los tres comparten firma a propósito: son OOXML, es decir, ZIP.
                'docx' => "PK\x03\x04",
                'xlsx' => "PK\x03\x04",
                'pptx' => "PK\x03\x04",
            ],
            ['jpeg' => 'jpg'],
            ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'],
            // Solo el PDF admite cabecera desplazada, igual que en la configuración real.
            ['pdf' => 1024],
        );
    }

    /** BOM UTF-8: lo más habitual que se cuela por delante de la cabecera de un PDF. */
    public const BOM = "\xEF\xBB\xBF";
}
