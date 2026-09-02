<?php

declare(strict_types=1);

use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentStoragePath;

/*
| Construcción de la clave con la que se guarda un adjunto.
|
| Dos cosas se prueban aquí: que el formato de la clave sea el acordado —de él dependen las
| políticas de acceso y de retención por cliente cuando esto viva en un bucket— y que ningún
| segmento pueda usarse para escapar del prefijo.
*/

function attachmentOwnerForStudy(?string $scope = 'documents'): AttachmentOwner
{
    return AttachmentOwner::for('studies', 'study', 42, $scope);
}

it('arma la clave con app, tenant, dueño y nombre físico', function () {
    $path = AttachmentStoragePath::build('fintegra', 'acme', attachmentOwnerForStudy(), '01K7XV8QW2.pdf');

    expect($path->key)->toBe('fintegra/acme/studies/study/42/documents/01K7XV8QW2.pdf');
});

it('omite el ámbito cuando el dueño no lo declara', function () {
    $path = AttachmentStoragePath::build('fintegra', 'acme', attachmentOwnerForStudy(null), '01K7XV8QW2.pdf');

    expect($path->key)->toBe('fintegra/acme/studies/study/42/01K7XV8QW2.pdf');
});

it('separa a dos clientes distintos con el mismo id de estudio', function () {
    $unTenant = AttachmentStoragePath::build('fintegra', 'acme', attachmentOwnerForStudy(), '01K7XV8QW2.pdf');
    $otroTenant = AttachmentStoragePath::build('fintegra', 'globex', attachmentOwnerForStudy(), '01K7XV8QW2.pdf');

    expect($unTenant->key)->not->toBe($otroTenant->key);
});

it('rechaza segmentos con los que se podría escapar del prefijo', function (string $app, string $tenant) {
    AttachmentStoragePath::build($app, $tenant, attachmentOwnerForStudy(), '01K7XV8QW2.pdf');
})->with([
    'tenant con ../' => ['fintegra', '../etc'],
    'tenant con barra' => ['fintegra', 'acme/prod'],
    'tenant con punto' => ['fintegra', 'acme.prod'],
    'app con ../' => ['../fintegra', 'acme'],
    'tenant vacío' => ['fintegra', ''],
])->throws(InvalidArgumentException::class);

it('rechaza un nombre físico que no sea un identificador con extensión', function (string $fileName) {
    AttachmentStoragePath::build('fintegra', 'acme', attachmentOwnerForStudy(), $fileName);
})->with([
    'con ruta' => ['../01K7XV8QW2.pdf'],
    'con espacios' => ['mi archivo.pdf'],
    'sin extensión' => ['01K7XV8QW2'],
    'con doble extensión' => ['01K7XV8QW2.pdf.exe'],
])->throws(InvalidArgumentException::class);

it('rechaza un dueño con datos que no sirven como segmento de ruta', function (string $module, string $entity, int $id) {
    AttachmentOwner::for($module, $entity, $id);
})->with([
    'módulo en mayúsculas' => ['Studies', 'study', 42],
    'módulo con barra' => ['studies/x', 'study', 42],
    'entidad vacía' => ['studies', '', 42],
    'id cero' => ['studies', 'study', 0],
    'id negativo' => ['studies', 'study', -1],
])->throws(InvalidArgumentException::class);

it('trata el ámbito vacío como ausente', function () {
    $path = AttachmentStoragePath::build('fintegra', 'acme', AttachmentOwner::for('studies', 'study', 42, ''), '01K7XV8QW2.pdf');

    expect($path->key)->toBe('fintegra/acme/studies/study/42/01K7XV8QW2.pdf');
});
