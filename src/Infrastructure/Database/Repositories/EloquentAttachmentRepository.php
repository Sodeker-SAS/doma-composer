<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Database\Repositories;

use Sodeker\Attachments\Domain\Entities\Attachment as AttachmentEntity;
use Sodeker\Attachments\Domain\Repositories\AttachmentRepositoryInterface;
use Sodeker\Attachments\Infrastructure\Database\Models\Attachment as AttachmentModel;
use DateTimeImmutable;

final class EloquentAttachmentRepository implements AttachmentRepositoryInterface
{
    public function save(AttachmentEntity $attachment): AttachmentEntity
    {
        $model = new AttachmentModel;
        $model->uuid = $attachment->uuid();
        $model->name = $attachment->name();
        $model->storage_location = $attachment->storageLocation();
        $model->url = $attachment->url();
        $model->extension = $attachment->extension();
        $model->size = $attachment->size();
        $model->status = $attachment->status();
        $model->created_by = $attachment->createdBy();
        $model->updated_by = $attachment->updatedBy();
        $model->created_at = now();
        $model->updated_at = now();
        $model->save();
        $model->refresh();

        return $this->toDomain($model);
    }

    public function findByUuid(string $uuid): ?AttachmentEntity
    {
        // Sin `withTrashed()`: un adjunto dado de baja no debe poder volver a leerse ni
        // borrarse, así que para quien pregunta simplemente no existe.
        $model = AttachmentModel::query()->where('uuid', $uuid)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function deleteByUuid(string $uuid): bool
    {
        $model = AttachmentModel::query()->where('uuid', $uuid)->first();

        return $model !== null && (bool) $model->delete();
    }

    private function toDomain(AttachmentModel $model): AttachmentEntity
    {
        return new AttachmentEntity(
            id: (int) $model->id,
            uuid: (string) $model->uuid,
            name: (string) $model->name,
            storageLocation: (string) $model->storage_location,
            url: (string) $model->url,
            extension: (string) $model->extension,
            size: (int) $model->size,
            status: (string) $model->status,
            createdBy: (string) $model->created_by,
            updatedBy: (string) $model->updated_by,
            createdAt: new DateTimeImmutable($model->created_at?->toAtomString() ?? 'now'),
            updatedAt: $model->updated_at
                ? new DateTimeImmutable($model->updated_at->toAtomString())
                : null,
            deletedAt: $model->deleted_at
                ? new DateTimeImmutable($model->deleted_at->toAtomString())
                : null,
        );
    }
}
