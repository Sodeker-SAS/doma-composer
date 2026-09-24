<?php

declare(strict_types=1);

use Sodeker\Attachments\Domain\Exceptions\ArchiveRejectedException;
use Sodeker\Attachments\Domain\ValueObjects\ArchiveKey;

/*
| La clave del archivo de sistema la decide quien llama y se respeta tal cual. Estas pruebas
| cubren las dos mitades: que una ruta legítima pasa sin retoques y que ninguna forma de salir de
| la raíz del destino, o de ensuciar el nombre, llega a escribirse.
*/

it('respeta la clave tal cual y extrae la extensión', function (string $key) {
    $archiveKey = ArchiveKey::fromString($key);

    expect($archiveKey->value)->toBe($key)
        ->and($archiveKey->extension)->toBe('dump');
})->with([
    'tenant' => 'backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump',
    'landlord' => 'backups/landlord/23_09_2026/landlord_23_09_2026_02_00_00.dump',
    'con guiones' => 'backups/tenants/prevesa/23-09-2026/prevesa_01-00-03.dump',
    'sin carpetas' => 'copia.dump',
]);

it('normaliza solo la extensión, nunca el nombre', function () {
    $archiveKey = ArchiveKey::fromString('backups/Prevesa/Copia.DUMP');

    expect($archiveKey->value)->toBe('backups/Prevesa/Copia.DUMP')
        ->and($archiveKey->extension)->toBe('dump');
});

it('rechaza claves que podrían salir de la raíz o no son rutas limpias', function (string $key) {
    ArchiveKey::fromString($key);
})->throws(ArchiveRejectedException::class)->with([
    'vacía' => '',
    'absoluta' => '/backups/copia.dump',
    'sube de nivel' => '../copia.dump',
    'sube a mitad de ruta' => 'backups/../../etc/copia.dump',
    'punto' => 'backups/./copia.dump',
    'doble barra' => 'backups//copia.dump',
    'termina en barra' => 'backups/',
    'separador de Windows' => 'backups\\copia.dump',
    'espacio interno' => 'backups/copia final.dump',
    'espacio alrededor' => ' backups/copia.dump',
    'archivo oculto' => 'backups/.copia.dump',
    'byte nulo' => "backups/copia.dump\0.txt",
    'acentos' => 'backups/compañía/copia.dump',
    'sin extensión' => 'backups/copia',
    'punto final' => 'backups/copia.',
]);

it('rechaza una clave desmesurada', function () {
    ArchiveKey::fromString(str_repeat('a', 1020).'.dump');
})->throws(ArchiveRejectedException::class, 'supera 1024 caracteres');
