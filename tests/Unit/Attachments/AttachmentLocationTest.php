<?php

declare(strict_types=1);

use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;

/*
| La ubicación física de un adjunto.
|
| Es el objeto que hace que cambiarle el destino a un cliente no rompa la lectura de lo que ya
| tenía guardado, así que lo que se prueba aquí es que el formato aguanta la ida y vuelta y que
| el nombre de disco que produce es estable.
*/

it('va y vuelve del formato que se guarda en la base', function (string $texto) {
    expect((string) AttachmentLocation::fromString($texto, 'public'))->toBe($texto);
})->with([
    'disco local' => ['local:public'],
    'bucket de s3' => ['s3:fintegra-hualo-adjuntos'],
]);

it('interpreta una ubicación vacía como el disco por defecto', function (?string $vacia) {
    // Son los adjuntos anteriores a que existiera la columna: todos están en el disco de siempre.
    expect((string) AttachmentLocation::fromString($vacia, 'public'))->toBe('local:public');
})->with([
    'null' => [null],
    'cadena vacía' => [''],
    'espacios' => ['   '],
]);

it('rechaza un almacenamiento que no está en la lista blanca', function () {
    // El valor decide con qué adaptador se abre el archivo, así que no puede ser cualquier cosa.
    AttachmentLocation::fromString('ftp:servidor-viejo', 'public');
})->throws(InvalidArgumentException::class, 'no está soportado');

it('rechaza un destino que podría escapar de la ruta', function (string $destino) {
    AttachmentLocation::of('local', $destino);
})->throws(InvalidArgumentException::class, 'inválido')->with([
    'con barra' => ['../../etc'],
    'vacío' => [''],
    'con dos puntos' => ['bucket:otro'],
]);

it('exige el formato driver:destino', function () {
    AttachmentLocation::fromString('solo-el-bucket', 'public');
})->throws(InvalidArgumentException::class, 'driver:destino');

it('deriva un nombre de disco estable y usable como clave de configuración', function () {
    $ubicacion = AttachmentLocation::of('s3', 'fintegra-hualo-adjuntos');

    // Sin puntos ni guiones: un punto partiría `filesystems.disks.<nombre>` en varios niveles.
    expect($ubicacion->diskName())->toBe('att_s3_fintegra_hualo_adjuntos')
        ->and($ubicacion->diskName())->toMatch('/^[a-z0-9_]+$/');
});

it('da el mismo nombre de disco a la misma ubicación y distinto a otra', function () {
    // Es la propiedad de la que depende que la caché de `Storage` deje de ser un problema:
    // el nombre sale del destino, que es inmutable, y no del cliente.
    $unBucket = AttachmentLocation::of('s3', 'bucket-a');
    $elMismo = AttachmentLocation::fromString('s3:bucket-a', 'public');
    $otro = AttachmentLocation::of('s3', 'bucket-b');

    expect($unBucket->diskName())->toBe($elMismo->diskName())
        ->and($unBucket->equals($elMismo))->toBeTrue()
        ->and($unBucket->diskName())->not->toBe($otro->diskName());
});

it('distingue dos buckets aunque compartan un prefijo largo', function () {
    // El nombre ya no se recorta —no se persiste en ninguna columna—, y no debe recortarse:
    // dos destinos con el mismo nombre volverían a compartir instancia en la caché de
    // `Storage` y los adjuntos de un cliente acabarían escribiéndose en el bucket del otro.
    $prefijo = str_repeat('a', 60);

    expect(AttachmentLocation::of('s3', $prefijo.'uno')->diskName())
        ->not->toBe(AttachmentLocation::of('s3', $prefijo.'dos')->diskName());
});

it('acepta un disco declarado que no es local, con su propio driver', function (string $texto) {
    // El NAS de un cliente por sftp, un ftp, un s3 con endpoint propio: los tres se describen
    // igual —`disk:<nombre del disco>`— porque el protocolo y las credenciales viven en
    // `filesystems.php`, no en la ubicación.
    expect((string) AttachmentLocation::fromString($texto, 'public'))->toBe($texto);
})->with([
    'nas del cliente' => ['disk:nas'],
    'un segundo nas' => ['disk:nas_acme'],
]);

it('no confunde el mismo nombre de disco en local y en declarado', function () {
    // `storage_location` existe para decir la verdad sobre dónde quedó el archivo, así que
    // `local:nas` y `disk:nas` NO pueden ser lo mismo: si lo fueran, el día que haya que migrar
    // no habría forma de separar lo que está en el servidor de lo que está en el NAS.
    $enElServidor = AttachmentLocation::localDisk('nas');
    $enElNas = AttachmentLocation::declaredDisk('nas');

    expect($enElServidor->equals($enElNas))->toBeFalse()
        ->and($enElServidor->diskName())->not->toBe($enElNas->diskName())
        ->and((string) $enElNas)->toBe('disk:nas');
});

it('sigue rechazando que la ubicación nombre un protocolo', function () {
    // El protocolo se declara en `filesystems.php`. Admitirlo aquí obligaría a ampliar la lista
    // blanca por cada tecnología nueva y a arriesgar que la fila del tenant y el disco declarado
    // dijeran cosas distintas.
    AttachmentLocation::fromString('sftp:nas', 'public');
})->throws(InvalidArgumentException::class, 'no está soportado');
