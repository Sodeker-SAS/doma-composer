<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'storage_location',
        'url',
        'extension',
        'size',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function getTable(): string
    {
        return (string) config('attachments.table', 'attachments');
    }

    public function getConnectionName(): ?string
    {
        return config('attachments.connection', 'tenant');
    }
}
