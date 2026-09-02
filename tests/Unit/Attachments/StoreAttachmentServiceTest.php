<?php

declare(strict_types=1);

use Sodeker\Attachments\Application\Services\StoreAttachmentService;
use Sodeker\Attachments\Domain\Exceptions\AttachmentStorageFailedException;
use Sodeker\Attachments\Domain\Exceptions\AttachmentTooLargeException;
use Sodeker\Attachments\Domain\Exceptions\UnsupportedAttachmentTypeException;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;
use Sodeker\Attachments\Tests\Support\AttachmentFixtures;
use Sodeker\Attachments\Tests\Support\FakeAttachmentBlobStorage;
use Sodeker\Attachments\Tests\Support\FakeStorageTenantResolver;
use Sodeker\Attachments\Tests\Support\FakeTenantDiskResolver;
use Sodeker\Attachments\Tests\TestCase;

/*
| El caso de uso completo, con dobles del almacenamiento y del resolvedor de tenant.
|
| Arranca el framework (necesita `config()`) pero no toca base de datos ni disco: la política de
| tipos sale de `config/attachments.php` de verdad, así que estas pruebas también avisan si
| alguien cambia esa configuración sin querer.
*/

uses(TestCase::class);

beforeEach(function () {
    $this->blobs = new FakeAttachmentBlobStorage;
    $this->disks = new FakeTenantDiskResolver;
    $this->service = new StoreAttachmentService($this->blobs, new FakeStorageTenantResolver('acme'), $this->disks);
    $this->owner = AttachmentOwner::for('studies', 'study', 42, 'documents');
});

it('guarda el archivo y describe dónde quedó', function () {
    $contenido = AttachmentFixtures::PDF.' contenido del contrato';

    $stored = $this->service->store(AttachmentFixtures::of($contenido, 'contrato de arrendamiento.pdf'), $this->owner);

    expect((string) $stored->location)->toBe('local:public')
        ->and($stored->originalName)->toBe('contrato de arrendamiento.pdf')
        ->and($stored->extension)->toBe('pdf')
        ->and($stored->sizeBytes)->toBe(strlen($contenido))
        ->and($this->blobs->written['local:public'][$stored->key])->toBe($contenido);
});

it('nombra el archivo con un ULID y nunca con el nombre que eligió quien llama', function () {
    $stored = $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'cédula de la señora.pdf'),
        $this->owner,
    );

    expect($stored->key)->toMatch('#^app/acme/studies/study/42/documents/[0-9A-Z]{26}\.pdf$#')
        ->and($stored->key)->not->toContain('cédula');
});

it('reconoce la familia de Office leyendo la configuración real', function (string $part, string $esperada) {
    // Esta prueba va contra `config/attachments.php` de verdad, no contra la política de
    // juguete: es la que avisaría si `container_markers` dejara de llegar al caso de uso, que
    // es donde estaba el fallo original —un docx se rechazaba diciendo que «es xlsx»—.
    $stored = $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::ooxml($part), 'documento.'.$esperada),
        $this->owner,
    );

    expect($stored->extension)->toBe($esperada)
        ->and($stored->key)->toEndWith('.'.$esperada);
})->with([
    'docx' => ['word/document.xml', 'docx'],
    'xlsx' => ['xl/workbook.xml', 'xlsx'],
    'pptx' => ['ppt/presentation.xml', 'pptx'],
]);

it('rechaza un zip corriente aunque se llame xlsx, con la configuración real', function () {
    $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::ooxml('cualquier/cosa.txt'), 'libro.xlsx'),
        $this->owner,
    );
})->throws(UnsupportedAttachmentTypeException::class, 'su contenido no se reconoce como tal');

it('acepta un pdf con la cabecera desplazada, leyendo la configuración real', function (string $estorbo) {
    // El caso reportado: un PDF corriente que no empieza exactamente en «%PDF» porque arrastra
    // un BOM o un salto de línea de algún conversor. Va contra `config/attachments.php` para
    // que falle si `signature_search_bytes` deja de llegar al caso de uso.
    $stored = $this->service->store(
        AttachmentFixtures::of($estorbo.AttachmentFixtures::PDF.' balance', 'balance2.pdf'),
        $this->owner,
    );

    expect($stored->extension)->toBe('pdf')
        ->and($stored->key)->toEndWith('.pdf');
})->with([
    'BOM UTF-8' => [AttachmentFixtures::BOM],
    'salto de línea' => ["\n"],
    'espacios' => ['   '],
]);

it('pone en la ruta la extensión del contenido y no la declarada', function () {
    // Un PDF enviado sin extensión en el nombre: la clave termina en .pdf porque lo dicen los
    // bytes, que es la única fuente en la que este módulo confía.
    $stored = $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'documento_sin_extension'),
        $this->owner,
    );

    expect($stored->extension)->toBe('pdf')
        ->and($stored->key)->toEndWith('.pdf');
});

it('rechaza un archivo por encima del máximo antes de escribir nada', function () {
    config()->set('attachments.max_size_bytes', 10);

    try {
        $this->service->store(AttachmentFixtures::of(AttachmentFixtures::PDF.str_repeat('x', 50), 'grande.pdf'), $this->owner);
        $this->fail('Se esperaba que rechazara el archivo por tamaño.');
    } catch (AttachmentTooLargeException $e) {
        expect($e->isCallerFault())->toBeTrue()
            ->and($this->blobs->written)->toBeEmpty();
    }
});

it('rechaza un formato no permitido antes de escribir nada', function () {
    try {
        $this->service->store(AttachmentFixtures::of(AttachmentFixtures::EXE, 'inofensivo.pdf'), $this->owner);
        $this->fail('Se esperaba que rechazara el ejecutable.');
    } catch (UnsupportedAttachmentTypeException $e) {
        expect($e->isCallerFault())->toBeTrue()
            ->and($this->blobs->written)->toBeEmpty();
    }
});

it('no le atribuye al consumidor un fallo del almacenamiento', function () {
    // La distinción que decide el status HTTP: un disco lleno no es culpa de quien envió.
    $this->blobs->failsOnPut = true;

    try {
        $this->service->store(AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'contrato.pdf'), $this->owner);
        $this->fail('Se esperaba un fallo de almacenamiento.');
    } catch (AttachmentStorageFailedException $e) {
        expect($e->isCallerFault())->toBeFalse();
    }
});

/*
| Reparto entre destinos según qué es el documento.
|
| Es lo que permite que un mismo cliente mande los documentos de sus aplicantes a un bucket y los
| del inmueble a su disco propio. El destino sale del PROPÓSITO del dueño, que se deriva del
| ámbito, y la búsqueda va de lo específico al comodín.
*/
it('manda cada categoría al destino declarado para ella', function () {
    $disks = new FakeTenantDiskResolver([
        'studies.applicant' => AttachmentLocation::of('s3', 'bucket-aplicantes'),
        'studies.property' => AttachmentLocation::localDisk('public'),
    ]);
    $service = new StoreAttachmentService($this->blobs, new FakeStorageTenantResolver('acme'), $disks);

    $delAplicante = $service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' cedula', 'cedula.pdf'),
        AttachmentOwner::for('studies', 'study', 42, 'applicant'),
    );
    $delInmueble = $service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' predial', 'predial.pdf'),
        AttachmentOwner::for('studies', 'study', 42, 'property'),
    );

    expect((string) $delAplicante->location)->toBe('s3:bucket-aplicantes')
        ->and((string) $delInmueble->location)->toBe('local:public')
        ->and($disks->asked)->toBe(['studies.applicant', 'studies.property'])
        // Y cada archivo está donde le toca, no solo declarado.
        ->and($this->blobs->keysAt('s3:bucket-aplicantes'))->toBe([$delAplicante->key])
        ->and($this->blobs->keysAt('local:public'))->toBe([$delInmueble->key]);
});

it('cae en el comodín del cliente cuando la categoría no tiene destino propio', function () {
    $disks = new FakeTenantDiskResolver(['attachments' => AttachmentLocation::of('s3', 'bucket-general')]);
    $service = new StoreAttachmentService($this->blobs, new FakeStorageTenantResolver('acme'), $disks);

    $stored = $service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'anexo.pdf'),
        AttachmentOwner::for('studies', 'study', 42, 'applicant'),
    );

    expect((string) $stored->location)->toBe('s3:bucket-general');
});

it('usa el disco por defecto cuando el cliente no declara ningún destino', function () {
    // Es el caso de todos los clientes hoy: sin filas en `tenant_disks` nada cambia.
    $stored = $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'anexo.pdf'),
        AttachmentOwner::for('studies', 'study', 42, 'applicant'),
    );

    expect((string) $stored->location)->toBe('local:public');
});

it('mete la categoría en la ruta física, separando las familias de archivos', function () {
    $stored = $this->service->store(
        AttachmentFixtures::of(AttachmentFixtures::PDF.' x', 'cedula.pdf'),
        AttachmentOwner::for('studies', 'study', 42, 'applicant'),
    );

    expect($stored->key)->toMatch('#^app/acme/studies/study/42/applicant/[0-9A-Z]{26}\.pdf$#');
});

/*
| Cierre del descriptor.
|
| El caso de uso toma posesión del flujo, así que debe soltarlo pase lo que pase — también
| cuando rechaza el archivo. Antes solo se cerraba en el camino feliz, y cada archivo rechazado
| dejaba un temporal abierto hasta el final de la petición.
*/
it('cierra el flujo tanto si acepta el archivo como si lo rechaza', function (string $contenido, string $nombre, int $maxBytes, bool $almacenamientoFalla) {
    config()->set('attachments.max_size_bytes', $maxBytes);
    $this->blobs->failsOnPut = $almacenamientoFalla;

    $stream = AttachmentFixtures::stream($contenido);
    $binary = AttachmentBinary::fromStream($stream, $nombre, strlen($contenido));

    try {
        $this->service->store($binary, $this->owner);
    } catch (Throwable) {
        // Da igual cómo termine: lo que se comprueba es que no queda el descriptor abierto.
    }

    expect(is_resource($stream))->toBeFalse();
})->with([
    'aceptado' => [AttachmentFixtures::PDF.' x', 'contrato.pdf', 52428800, false],
    'rechazado por tamaño' => [AttachmentFixtures::PDF.' x', 'contrato.pdf', 2, false],
    'rechazado por formato' => [AttachmentFixtures::EXE, 'inofensivo.pdf', 52428800, false],
    'falla el almacenamiento' => [AttachmentFixtures::PDF.' x', 'contrato.pdf', 52428800, true],
]);
