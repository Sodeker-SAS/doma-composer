<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Sodeker\Attachments\Application\Services\ArchiveFileService;
use Sodeker\Attachments\Contracts\ArchiveStoragePort;
use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveRejectedException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveStorageFailedException;
use Sodeker\Attachments\Tests\Support\ArchiveFixtures;
use Sodeker\Attachments\Tests\Support\FakeArchiveBlobStorage;
use Sodeker\Attachments\Tests\TestCase;

/*
| El archivo de sistema completo, en dos tandas.
|
| La primera va contra un disco local DE VERDAD, resuelto por el contenedor igual que en una
| aplicación: prueba el cableado del provider, la validación del destino y el recorrido real de
| Flysystem (escritura por streaming, carpetas intermedias, renombrado). Un disco `sftp` usa ese
| mismo recorrido; lo único que cambia es el adaptador.
|
| La segunda usa un destino en memoria que falla a voluntad, para lo que un disco local nunca
| hace: cortarse a mitad, perder bytes o negarse a renombrar.
*/

uses(TestCase::class);

const CLAVE_TENANT = 'backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump';
const CLAVE_LANDLORD = 'backups/landlord/23_09_2026/landlord_23_09_2026_02_00_00.dump';

beforeEach(function () {
    $this->origen = ArchiveFixtures::tempDir();
    $this->nas = ArchiveFixtures::tempDir();

    config()->set('filesystems.disks.nas_pruebas', ['driver' => 'local', 'root' => $this->nas, 'throw' => true]);
    config()->set('attachments.archive.disk', 'nas_pruebas');
    config()->set('attachments.archive.retry_delay_seconds', 0);
});

afterEach(function () {
    ArchiveFixtures::removeDir($this->origen);
    ArchiveFixtures::removeDir($this->nas);
});

// ─── Contra un disco real ───────────────────────────────────────────────────────────────────

it('guarda el dump con la clave exacta y describe dónde quedó', function () {
    $local = ArchiveFixtures::dump($this->origen);

    $archived = app(ArchiveStoragePort::class)->store($local, CLAVE_TENANT);

    expect($archived->key)->toBe(CLAVE_TENANT)
        ->and($archived->destination)->toBe('nas_pruebas')
        ->and($archived->sizeBytes)->toBe(filesize($local))
        ->and($archived->sha256)->toBe(hash_file('sha256', $local))
        ->and($archived->attempts)->toBe(1)
        ->and($archived->alreadyArchived)->toBeFalse()
        ->and(file_get_contents($this->nas.'/'.CLAVE_TENANT))->toBe(file_get_contents($local));
});

it('no deja temporales en el destino y no toca el archivo local', function () {
    $local = ArchiveFixtures::dump($this->origen);
    $original = file_get_contents($local);

    app(ArchiveStoragePort::class)->store($local, CLAVE_LANDLORD);

    expect(ArchiveFixtures::filesIn($this->nas))->toBe([CLAVE_LANDLORD])
        ->and(file_get_contents($local))->toBe($original);
});

it('sube un volcado pesado por streaming, sin cargarlo en memoria', function () {
    $bytes = 96 * 1024 * 1024;
    $local = ArchiveFixtures::largeDump($this->origen, $bytes);
    $port = app(ArchiveStoragePort::class);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    $archived = $port->store($local, CLAVE_TENANT);

    // Si el archivo pasara por memoria, el pico crecería lo que pesa: 96 MB.
    expect(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024)
        ->and($archived->sizeBytes)->toBe($bytes)
        ->and(filesize($this->nas.'/'.CLAVE_TENANT))->toBe($bytes)
        ->and(hash_file('sha256', $this->nas.'/'.CLAVE_TENANT))->toBe($archived->sha256);
});

it('repetir la misma subida es inocuo y no vuelve a transferir', function () {
    $local = ArchiveFixtures::dump($this->origen);
    $port = app(ArchiveStoragePort::class);

    $port->store($local, CLAVE_TENANT);
    $again = $port->store($local, CLAVE_TENANT);

    expect($again->alreadyArchived)->toBeTrue()
        ->and($again->attempts)->toBe(0)
        ->and(ArchiveFixtures::filesIn($this->nas))->toBe([CLAVE_TENANT]);
});

it('no sobrescribe lo archivado con otro contenido', function () {
    $port = app(ArchiveStoragePort::class);
    $port->store(ArchiveFixtures::dump($this->origen, 'a.dump', ' primera copia'), CLAVE_TENANT);

    expect(fn () => $port->store(ArchiveFixtures::dump($this->origen, 'b.dump', ' otra copia más larga'), CLAVE_TENANT))
        ->toThrow(ArchiveRejectedException::class, 'Lo archivado no se sobrescribe');

    expect(file_get_contents($this->nas.'/'.CLAVE_TENANT))->toBe('PGDMP primera copia');
});

// ─── Qué se archiva ─────────────────────────────────────────────────────────────────────────

it('solo archiva los tipos declarados', function () {
    $local = ArchiveFixtures::dump($this->origen);

    expect(fn () => app(ArchiveStoragePort::class)->store($local, 'backups/prevesa/copia.sql'))
        ->toThrow(ArchiveRejectedException::class, 'no se archiva');

    expect(ArchiveFixtures::filesIn($this->nas))->toBe([]);
});

it('las extensiones bloqueadas ganan aunque alguien las declare como archivables', function () {
    config()->set('attachments.archive.types', ['dump' => 'PGDMP', 'php' => null]);
    $local = ArchiveFixtures::file($this->origen, 'script.php', '<?php echo 1;');

    expect(fn () => app(ArchiveStoragePort::class)->store($local, 'backups/script.php'))
        ->toThrow(ArchiveRejectedException::class, 'bloqueada por seguridad');

    expect(ArchiveFixtures::filesIn($this->nas))->toBe([]);
});

it('rechaza un .dump que no empieza por la firma de pg_dump', function () {
    // Lo que queda en el archivo cuando pg_dump falla y alguien redirige su salida.
    $local = ArchiveFixtures::file($this->origen, 'origen.dump', 'pg_dump: error: connection to server failed');

    expect(fn () => app(ArchiveStoragePort::class)->store($local, CLAVE_TENANT))
        ->toThrow(ArchiveRejectedException::class, 'no empieza por la firma');

    expect(ArchiveFixtures::filesIn($this->nas))->toBe([]);
});

it('rechaza un archivo local ausente o vacío', function (string $case) {
    $local = $case === 'vacío'
        ? ArchiveFixtures::file($this->origen, 'vacio.dump', '')
        : $this->origen.'/no-existe.dump';

    expect(fn () => app(ArchiveStoragePort::class)->store($local, CLAVE_TENANT))
        ->toThrow(ArchiveRejectedException::class);

    expect(ArchiveFixtures::filesIn($this->nas))->toBe([]);
})->with(['ausente', 'vacío']);

// ─── A dónde se archiva ─────────────────────────────────────────────────────────────────────

it('sin destino configurado falla en lugar de caer a un disco por defecto', function () {
    config()->set('attachments.archive.disk', null);

    app(ArchiveStoragePort::class)->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);
})->throws(ArchiveDestinationException::class, 'ATTACHMENTS_ARCHIVE_DISK');

it('rechaza un disco que no está declarado', function () {
    config()->set('attachments.archive.disk', 'nas_inexistente');

    app(ArchiveStoragePort::class)->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);
})->throws(ArchiveDestinationException::class, 'no está declarado');

it('nunca escribe en un disco de acceso público', function (Closure $disk) {
    config()->set('filesystems.disks.destino_publico', $disk());
    config()->set('attachments.archive.disk', 'destino_publico');

    app(ArchiveStoragePort::class)->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);
})->throws(ArchiveDestinationException::class, 'acceso público')->with([
    'visibilidad pública' => fn () => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'visibility' => 'public'],
    'raíz dentro de storage/app/public' => fn () => ['driver' => 'local', 'root' => storage_path('app/public/copias')],
    'raíz dentro de public/' => fn () => ['driver' => 'local', 'root' => public_path('copias')],
]);

it('nunca escribe en el disco public por nombre', function () {
    config()->set('attachments.archive.disk', 'public');

    app(ArchiveStoragePort::class)->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);
})->throws(ArchiveDestinationException::class, 'acceso público');

// ─── Transporte que falla ───────────────────────────────────────────────────────────────────

it('reintenta el transporte y limpia el temporal de cada intento fallido', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->failWrites = 2;

    $archived = (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);

    expect($archived->attempts)->toBe(3)
        ->and(array_keys($storage->files))->toBe([CLAVE_TENANT])
        ->and($storage->deleted)->toHaveCount(2)
        ->and($storage->deleted[0])->toStartWith(CLAVE_TENANT.'.')->toEndWith('.part')
        ->and($storage->deleted[0])->not->toBe($storage->deleted[1]);
});

it('agota los intentos sin que el nombre final llegue a existir', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->failWrites = 10;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT))
        ->toThrow(ArchiveStorageFailedException::class, 'Conexión reiniciada');

    expect($storage->writtenKeys)->toHaveCount(3)
        ->and($storage->files)->toBe([]);
});

it('una subida que pierde bytes no llega a su nombre final', function () {
    config()->set('attachments.archive.attempts', 1);
    $storage = new FakeArchiveBlobStorage;
    $storage->dropBytes = 1;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT))
        ->toThrow(ArchiveStorageFailedException::class, 'quedó incompleta');

    expect($storage->files)->toBe([]);
});

it('si el destino no deja renombrar, borra el temporal y falla', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->failMove = true;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT))
        ->toThrow(ArchiveStorageFailedException::class, 'Permiso denegado al renombrar');

    expect($storage->files)->toBe([])
        ->and($storage->deleted)->toHaveCount(3);
});

it('espera entre intentos lo que diga la configuración', function () {
    Sleep::fake();
    config()->set('attachments.archive.retry_delay_seconds', 10);
    $storage = new FakeArchiveBlobStorage;
    $storage->failWrites = 2;

    (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);

    Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(10)->seconds()]);
});

it('un destino que no contesta a la primera consulta también se reintenta', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->failExists = 2;

    $archived = (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT);

    expect($archived->attempts)->toBe(3)
        ->and(array_keys($storage->files))->toBe([CLAVE_TENANT]);
});

it('un destino que nunca contesta falla como transporte, no con el error crudo del adaptador', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->failExists = 10;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT))
        ->toThrow(ArchiveStorageFailedException::class, 'tiempo de espera agotado');

    expect($storage->writtenKeys)->toBe([]);
});

it('si se perdió la respuesta de un renombrado que sí ocurrió, el reintento lo reconoce como archivado', function () {
    $storage = new FakeArchiveBlobStorage;
    $storage->loseMoveResponse = true;
    $local = ArchiveFixtures::dump($this->origen);

    $archived = (new ArchiveFileService($storage))->store($local, CLAVE_TENANT);

    expect($archived->alreadyArchived)->toBeTrue()
        ->and($archived->attempts)->toBe(1)
        ->and($storage->writtenKeys)->toHaveCount(1)
        ->and($storage->files)->toBe([CLAVE_TENANT => file_get_contents($local)]);
});

it('unas credenciales rechazadas cortan en el primer intento, sin acercar el bloqueo del NAS', function () {
    Sleep::fake();
    $storage = new FakeArchiveBlobStorage;
    $storage->rejectCredentials = true;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), CLAVE_TENANT))
        ->toThrow(ArchiveDestinationException::class, 'rechazó las credenciales');

    expect($storage->existsCalls)->toBe(1)
        ->and($storage->writtenKeys)->toBe([]);

    Sleep::assertNeverSlept();
});

it('el mensaje del fallo lleva la causa real, no solo el envoltorio genérico del adaptador', function () {
    // La cadena exacta que produce Flysystem con una contraseña equivocada contra un SFTP real.
    $cause = new RuntimeException(
        'Unable to check existence for: '.CLAVE_TENANT,
        0,
        new RuntimeException('Unable to authenticate using a password.'),
    );

    $e = ArchiveStorageFailedException::forKey(CLAVE_TENANT, 'synology_backups', $cause);

    expect($e->getMessage())
        ->toContain('Unable to check existence for')
        ->toContain('Unable to authenticate using a password.')
        ->and($e->getPrevious())->toBe($cause);
});

it('un envío rechazado no abre el destino ni se reintenta', function () {
    $storage = new FakeArchiveBlobStorage;

    expect(fn () => (new ArchiveFileService($storage))->store(ArchiveFixtures::dump($this->origen), 'backups/../copia.dump'))
        ->toThrow(ArchiveRejectedException::class);

    expect($storage->writtenKeys)->toBe([]);
});
