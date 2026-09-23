<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use Sodeker\Attachments\Domain\Exceptions\UnsupportedAttachmentTypeException;

/**
 * El tipo REAL de un adjunto, deducido de sus primeros bytes.
 *
 * POR QUÉ NO BASTA LA EXTENSIÓN: el nombre del archivo y el `Content-Type` los escribe quien
 * llama, y aquí llaman aplicaciones que no controlamos. Renombrar un ejecutable a `.pdf` es
 * trivial; comparar la extensión contra una lista blanca solo comprueba que el atacante eligió
 * un nombre permitido. Los primeros bytes, en cambio, los pone el formato.
 *
 * LA REGLA: se reconoce el tipo por su firma binaria y se exige que la extensión declarada
 * COINCIDA con él. Un `.pdf` que por dentro es un ZIP se rechaza aunque el ZIP también esté en
 * la lista blanca —el desacuerdo entre nombre y contenido es, en sí mismo, la señal—.
 *
 * Es la validación que justifica que el archivo pase por Fintegra en lugar de ir directo al
 * almacenamiento: ocurre mientras el archivo todavía es nuestro y no ha llegado al bucket.
 */
final readonly class AttachmentContentType
{
    /**
     * Cuánta cabecera se inspecciona.
     *
     * Los magic bytes caben en ocho, pero los formatos OOXML —docx, xlsx, pptx— comparten los
     * suyos y solo se distinguen por el nombre de las entradas que llevan dentro del ZIP. Esos
     * nombres van sin comprimir en las cabeceras de cada entrada, y en un archivo de Office los
     * primeros kilobytes bastan de sobra para toparse con el que identifica al formato.
     */
    private const INSPECTION_BYTES = 8192;

    private function __construct(
        /** Extensión canónica del tipo detectado: pdf, jpg, png, xls, docx, xlsx, pptx. */
        public string $extension,
    ) {}

    /**
     * @throws UnsupportedAttachmentTypeException si el contenido no es de un tipo permitido, o
     *                                            si contradice la extensión declarada.
     */
    public static function resolve(AttachmentBinary $binary, AllowedAttachmentTypes $allowed): self
    {
        $declared = $allowed->canonical($binary->declaredExtension());

        // PRIMERO EL BLOQUEO, antes de leer un solo byte. Estas extensiones no se rechazan por
        // política de negocio sino porque son peligrosas al servirse, así que ningún módulo
        // consumidor puede autorizarlas y ninguna comprobación de contenido las salva.
        if ($declared !== '' && $allowed->isBlocked($declared)) {
            throw UnsupportedAttachmentTypeException::blocked($binary->originalName, $declared);
        }

        $detected = $allowed->detect($binary->head(self::INSPECTION_BYTES));

        if ($detected !== null) {
            // Sin extensión en el nombre nos quedamos con lo que digan los bytes: no hay nada que
            // contradecir. Con extensión, tiene que coincidir —o ser una declaración equivalente,
            // como un xlsm que por dentro es un xlsx—.
            if ($declared === '' || $declared === $detected) {
                return new self($detected);
            }

            // Se conserva la extensión DECLARADA, no la detectada: un Excel con macros es un
            // OOXML válido y guardarlo como `xlsx` le quitaría el subtipo que lo identifica.
            if ($allowed->acceptsDeclarationFor($detected, $declared)) {
                return new self($declared);
            }

            throw UnsupportedAttachmentTypeException::mismatch($binary->originalName, $declared, $detected);
        }

        // NINGUNA FIRMA ENCAJÓ. Aquí es donde entran los tipos sin huella: un `.log` o un `.txt`
        // son bytes arbitrarios y no hay nada contra lo que contrastarlos. Se aceptan por su
        // nombre, asumiendo conscientemente que un archivo renombrado pasaría.
        if ($allowed->isOpaque($declared)) {
            return new self($declared);
        }

        // Dos fallos que se leen igual pero no lo son. Si el formato declarado está en la
        // lista, el problema no es el formato sino el contenido, y decirle a quien envió un
        // PDF que «no se aceptan PDF» lo manda a buscar donde no es.
        throw $declared !== '' && $allowed->supports($declared)
            ? UnsupportedAttachmentTypeException::unreadable($binary->originalName, $declared)
            : UnsupportedAttachmentTypeException::unrecognized($binary->originalName, $allowed->label());
    }
}
