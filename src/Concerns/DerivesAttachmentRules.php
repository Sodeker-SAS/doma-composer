<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Concerns;

use Sodeker\Attachments\Domain\ValueObjects\AttachmentContentType;

/**
 * Reglas de validación de adjuntos DERIVADAS de `config/attachments.php`.
 *
 * POR QUÉ NO SE ESCRIBEN A MANO: antes cada request del ERP traía su propia lista
 * (`mimes:pdf,jpg,jpeg,png,xlsx,xls`) y su propio tope (`max:51200`). Eso convertía la política
 * de formatos en tres listas que había que recordar mantener a la vez, y ya habían divergido: el
 * módulo acepta además `docx` y `pptx`, así que el ERP rechazaba formatos que la API sí admitía.
 * Derivándolas de la configuración hay una sola fuente y añadir un formato es tocar un archivo.
 *
 * ESTO NO ES LA VALIDACIÓN DE VERDAD, y es importante no confundirlo: `mimes:` mira la extensión
 * y el tipo que adivina PHP, mientras que la autoridad sobre el formato es
 * {@see AttachmentContentType}, que compara los primeros bytes del archivo con la firma del
 * formato y exige que coincidan con la extensión declarada. Estas reglas solo existen para que la
 * web devuelva un error de formulario junto al campo en lugar de una excepción, y por eso no
 * pueden ser MÁS estrictas que el módulo.
 *
 * El request de la API (`StoreStudyDocumentsApiRequest`) omite `mimes:` a propósito y deja toda
 * la decisión al módulo; ahí no hay formulario que pintar.
 */
trait DerivesAttachmentRules
{
    /**
     * Reglas para cada binario recibido.
     *
     * @return list<string>
     */
    protected function attachmentFileRules(): array
    {
        return ['file', 'max:'.$this->attachmentMaxKilobytes(), 'mimes:'.implode(',', $this->allowedExtensions())];
    }

    /**
     * Extensiones aceptadas: las canónicas más sus alias, porque el usuario escribe `.jpeg` tanto
     * como `.jpg` y para `mimes:` son dos entradas distintas.
     *
     * @return list<string>
     */
    protected function allowedExtensions(): array
    {
        $canonical = array_keys((array) config('attachments.allowed_types', []));
        $aliases = array_keys((array) config('attachments.extension_aliases', []));

        /** @var list<string> $extensions */
        $extensions = array_values(array_unique(array_map(
            static fn ($extension): string => strtolower((string) $extension),
            [...$canonical, ...$aliases],
        )));

        sort($extensions);

        return $extensions;
    }

    /** El tope se guarda en bytes y la regla `max` de Laravel se expresa en kilobytes. */
    protected function attachmentMaxKilobytes(): int
    {
        return (int) round(((int) config('attachments.max_size_bytes', 52428800)) / 1024);
    }

    /** Tope en MB, para redactar el mensaje de error sin repetir el número. */
    protected function attachmentMaxMegabytes(): string
    {
        return (string) round($this->attachmentMaxKilobytes() / 1024);
    }
}
