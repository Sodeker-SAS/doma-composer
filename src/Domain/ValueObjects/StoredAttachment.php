<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

/**
 * Resultado de guardar un adjunto: dónde quedó y con qué características.
 *
 * POR QUÉ NO TRAE UNA `url`: el servicio anterior devolvía `asset('storage/…')`, una URL
 * pública y permanente, y quien la recibía la persistía. Eso tiene dos problemas que se pagan
 * al migrar a S3. Primero, la URL deja de ser válida en cuanto cambia el almacenamiento, así
 * que habría que reescribir filas de la base de datos. Segundo, un documento privado —una
 * cédula, un extracto bancario— no debe ser alcanzable por cualquiera que tenga el enlace.
 *
 * Lo que se persiste es la CLAVE. La URL se genera en el momento de leer, y así puede ser una
 * ruta local hoy y una URL firmada con vencimiento cuando el bucket esté en marcha, sin tocar
 * un solo registro.
 *
 * TRAE LA UBICACIÓN Y NO UN NOMBRE DE DISCO: un nombre es una etiqueta que se re-vincula
 * cuando cambia la configuración; la ubicación describe el destino en sí y por eso es lo que se
 * persiste para volver a encontrar el archivo. Ver {@see AttachmentLocation}.
 */
final readonly class StoredAttachment
{
    public function __construct(
        /** Ruta completa dentro del disco: es lo que se guarda en base de datos. */
        public string $key,
        /** Nombre con el que lo conoce el usuario, tal como llegó. */
        public string $originalName,
        /** Extensión canónica verificada contra el contenido, no la declarada. */
        public string $extension,
        public int $sizeBytes,
        /** Dónde quedó físicamente. Se persiste y manda en toda lectura posterior. */
        public AttachmentLocation $location,
    ) {}
}
