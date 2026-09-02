<?php

declare(strict_types=1);

use Sodeker\Attachments\Application\Services\StoreAttachmentService;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;
use Sodeker\Attachments\Tests\Support\AttachmentFixtures;
use Sodeker\Attachments\Tests\Support\FakeAttachmentBlobStorage;
use Sodeker\Attachments\Tests\Support\FakeStorageTenantResolver;
use Sodeker\Attachments\Tests\Support\FakeTenantDiskResolver;
use Sodeker\Attachments\Tests\TestCase;

/*
| El camino de lectura del módulo.
|
| Es lo que permite servir un adjunto desde la aplicación en vez de enlazar el almacenamiento,
| y lo que hay que asegurar es que va a buscar el archivo DONDE QUEDÓ y no donde el cliente
| escribiría hoy.
*/

uses(TestCase::class);

beforeEach(function () {
    $this->blobs = new FakeAttachmentBlobStorage;
    $this->owner = AttachmentOwner::for('studies', 'study', 42, 'documents');
});

/** Caso de uso con el destino que se le indique, para simular un cambio de configuración. */
function servicioQueEscribeEn(FakeAttachmentBlobStorage $blobs, AttachmentLocation $destino): StoreAttachmentService
{
    return new StoreAttachmentService(
        $blobs,
        new FakeStorageTenantResolver('acme'),
        new FakeTenantDiskResolver(['attachments' => $destino]),
    );
}

it('devuelve el contenido del adjunto que acaba de guardar', function () {
    $contenido = AttachmentFixtures::PDF.' contenido del contrato';
    $service = servicioQueEscribeEn($this->blobs, AttachmentLocation::localDisk('public'));

    $stored = $service->store(AttachmentFixtures::of($contenido, 'contrato.pdf'), $this->owner);
    $stream = $service->readStream((string) $stored->location, $stored->key);

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe($contenido);

    fclose($stream);
});

it('lee en la ubicación guardada aunque el cliente ya escriba en otra', function () {
    // La regresión que motivó la columna `storage_location`: cambiarle el destino a un cliente
    // no puede dejar ilegible lo que ya tenía guardado.
    $contenido = AttachmentFixtures::PDF.' cedula';
    $antiguo = servicioQueEscribeEn($this->blobs, AttachmentLocation::localDisk('public'));
    $stored = $antiguo->store(AttachmentFixtures::of($contenido, 'cedula.pdf'), $this->owner);

    // A partir de aquí el cliente escribe en un bucket; lo anterior sigue en el disco local.
    $nuevo = servicioQueEscribeEn($this->blobs, AttachmentLocation::of('s3', 'bucket-nuevo'));
    $stream = $nuevo->readStream((string) $stored->location, $stored->key);

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe($contenido);

    fclose($stream);
});

it('devuelve null cuando el objeto ya no está en esa ubicación', function () {
    // La fila puede existir sin que exista el archivo. Para quien lee es un 404, no un error.
    $service = servicioQueEscribeEn($this->blobs, AttachmentLocation::localDisk('public'));

    expect($service->readStream('local:public', 'fintegra/acme/studies/study/42/documents/NO_EXISTE.pdf'))->toBeNull();
});

it('interpreta una ubicación vacía como el disco por defecto', function () {
    // Son los adjuntos anteriores a la columna: la fila no dice dónde están, pero están todos
    // en el disco de siempre.
    $contenido = AttachmentFixtures::PDF.' anexo';
    $service = servicioQueEscribeEn($this->blobs, AttachmentLocation::localDisk('public'));
    $stored = $service->store(AttachmentFixtures::of($contenido, 'anexo.pdf'), $this->owner);

    $stream = $service->readStream('', $stored->key);

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe($contenido);

    fclose($stream);
});
