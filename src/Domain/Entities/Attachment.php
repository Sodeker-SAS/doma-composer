<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Entities;

use DateTimeImmutable;

/**
 * Un adjunto registrado, sin negocio de nadie.
 *
 * POR QUÉ NO TIENE `study_id` NI NADA PARECIDO: es la contrapartida en base de datos de lo que
 * el módulo ya hacía con las rutas. La entidad describe el ARCHIVO —dónde quedó, cuánto pesa,
 * qué formato resultó ser— y quién lo usa se resuelve en una pivote del módulo consumidor. Es lo
 * que permite que la misma tabla sirva a estudios, comprobantes y despachos.
 *
 * GUARDA `storageLocation` ADEMÁS DE LA CLAVE: la ruta sola no dice en qué almacenamiento
 * buscar. Y describe el destino EN SÍ, no el nombre con el que Laravel lo conoció al escribir
 * —ese nombre es una etiqueta que se re-vincula cuando el cliente cambia de configuración—, así
 * que cambiar dónde escribe un cliente afecta solo a los adjuntos NUEVOS en vez de obligar a
 * migrar los objetos anteriores.
 */
final class Attachment
{
    public function __construct(
        private ?int $id,
        private string $uuid,
        private string $name,
        private string $storageLocation,
        private string $url,
        private string $extension,
        private int $size,
        private string $status,
        private string $createdBy,
        private string $updatedBy,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt = null,
    ) {}

    /**
     * @param  string  $name  Nombre con el que lo conoce el usuario, no el físico.
     * @param  string  $storageLocation  Dónde quedó físicamente: `local:public`, `s3:bucket`.
     * @param  string  $url  Clave dentro del disco. NUNCA un enlace: ver `StoredAttachment`.
     */
    public static function create(
        string $uuid,
        string $name,
        string $storageLocation,
        string $url,
        string $extension,
        int $size,
        string $createdBy,
        string $updatedBy,
    ): self {
        return new self(
            id: null,
            uuid: $uuid,
            name: $name,
            storageLocation: $storageLocation,
            url: $url,
            extension: $extension,
            size: $size,
            status: '1',
            createdBy: $createdBy,
            updatedBy: $updatedBy,
            createdAt: new DateTimeImmutable,
            updatedAt: new DateTimeImmutable,
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Dónde quedó físicamente. Es la autoridad para volver a leerlo. */
    public function storageLocation(): string
    {
        return $this->storageLocation;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function updatedBy(): string
    {
        return $this->updatedBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
