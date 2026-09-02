<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A qué registro pertenece un adjunto, expresado sin que el módulo `Attachments` tenga que
 * conocer ese registro.
 *
 * POR QUÉ EXISTE: el módulo debe servir a cualquier dueño —un estudio hoy, un contrato o un
 * tercero mañana, y otra app del ecosistema después— sin acumular un `if` por cada uno. En vez
 * de recibir "un estudio", recibe las cuatro coordenadas que identifican a cualquier dueño:
 * módulo, entidad, id y un ámbito opcional para separar familias de archivos del mismo registro
 * (documentos, fotos, anexos).
 *
 * PARA QUÉ SE USA: es lo único que necesita {@see AttachmentStoragePath} para construir la
 * clave del objeto. Quien llama traduce su agregado a un `AttachmentOwner` y el módulo hace el
 * resto.
 */
final readonly class AttachmentOwner
{
    private function __construct(
        public string $module,
        public string $entity,
        public int $entityId,
        public ?string $scope,
    ) {}

    /**
     * @param  string  $module  Módulo dueño en slug: "studies".
     * @param  string  $entity  Entidad dentro del módulo: "study".
     * @param  int  $entityId  Id del registro concreto.
     * @param  string|null  $scope  Familia de archivos opcional: "documents".
     */
    public static function for(string $module, string $entity, int $entityId, ?string $scope = null): self
    {
        self::assertSlug($module, 'módulo');
        self::assertSlug($entity, 'entidad');

        if ($entityId <= 0) {
            throw new InvalidArgumentException('El identificador del registro dueño del adjunto es inválido.');
        }

        if ($scope !== null && $scope !== '') {
            self::assertSlug($scope, 'ámbito');
        }

        return new self($module, $entity, $entityId, $scope === '' ? null : $scope);
    }

    /**
     * Clave con la que se busca el destino de este adjunto en `landlord.tenant_disks`.
     *
     * PARA QUÉ SIRVE: permite que un mismo cliente reparta sus adjuntos entre destinos distintos
     * según QUÉ SON. Los documentos de los aplicantes de un estudio pueden ir a un bucket
     * mientras los del inmueble se quedan en el disco propio del cliente, y esa decisión la toma
     * el cliente declarando filas, no el código.
     *
     * SALE DEL ÁMBITO Y NO DE UN PARÁMETRO NUEVO: el ámbito ya es «la familia de archivos» de
     * este registro, que es exactamente el concepto que distingue un destino de otro. Así el
     * mismo dato segmenta la ruta física y elige el destino.
     *
     * Sin ámbito se devuelve solo el módulo, y quien no encuentre fila propia cae en el comodín
     * `attachments` — de ahí que un consumidor que no sepa nada de esto siga funcionando igual.
     *
     * OJO: `tenant_disks.purpose` es `varchar(50)`. Módulo y ámbito son slugs cortos, pero no
     * conviene alargarlos sin tener eso presente.
     */
    public function purpose(): string
    {
        return $this->scope === null ? $this->module : $this->module.'.'.$this->scope;
    }

    /**
     * Los segmentos van dentro de una ruta de almacenamiento, así que se restringen a un
     * alfabeto seguro: sin puntos ni barras, no hay forma de fabricar un `../` con ellos.
     */
    private static function assertSlug(string $value, string $label): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException("El nombre de {$label} del adjunto es inválido.");
        }
    }
}
