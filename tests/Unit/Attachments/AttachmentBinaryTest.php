<?php

declare(strict_types=1);

use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Tests\Support\AttachmentFixtures;

/*
| El archivo que entra al módulo, expresado sin framework.
|
| Lo que más importa cubrir aquí es que leer la firma NO consuma el contenido: quien almacena
| después lee el mismo flujo, y si `signature()` no rebobinara, el archivo se guardaría sin sus
| primeros bytes y nadie se enteraría hasta intentar abrirlo.
*/

it('rechaza construirse sin un flujo válido', function () {
    AttachmentBinary::fromStream('no soy un recurso', 'contrato.pdf', 10);
})->throws(InvalidArgumentException::class, 'no es un flujo válido');

it('rechaza un adjunto sin nombre', function () {
    AttachmentBinary::fromStream(AttachmentFixtures::stream('contenido'), '   ', 9);
})->throws(InvalidArgumentException::class, 'debe tener un nombre');

it('rechaza un adjunto vacío', function (int $size) {
    AttachmentBinary::fromStream(AttachmentFixtures::stream('contenido'), 'contrato.pdf', $size);
})->with(['cero' => [0], 'negativo' => [-5]])
    ->throws(InvalidArgumentException::class, 'está vacío');

it('deja el flujo intacto después de leer la firma', function () {
    $contenido = AttachmentFixtures::PDF.' resto del documento';
    $binary = AttachmentFixtures::of($contenido, 'contrato.pdf');

    expect($binary->head(8))->toBe(substr($contenido, 0, 8));

    // La comprobación que de verdad importa: el contenido sigue completo desde el byte cero.
    expect(stream_get_contents($binary->stream()))->toBe($contenido);
});

it('normaliza la extensión declarada a minúsculas', function (string $nombre, string $esperada) {
    expect(AttachmentFixtures::of('contenido', $nombre)->declaredExtension())->toBe($esperada);
})->with([
    'mayúsculas' => ['CEDULA.JPEG', 'jpeg'],
    'mixta' => ['Contrato.PdF', 'pdf'],
    'sin extensión' => ['contrato', ''],
    'con varios puntos' => ['acta.final.pdf', 'pdf'],
]);

it('puede cerrarse más de una vez sin fallar', function () {
    $stream = AttachmentFixtures::stream('contenido');
    $binary = AttachmentBinary::fromStream($stream, 'contrato.pdf', 9);

    $binary->close();
    $binary->close();

    expect(is_resource($stream))->toBeFalse();
});
