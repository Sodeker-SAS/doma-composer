<?php

declare(strict_types=1);

use Sodeker\Attachments\Domain\Exceptions\UnsupportedAttachmentTypeException;
use Sodeker\Attachments\Domain\ValueObjects\AllowedAttachmentTypes;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentContentType;
use Sodeker\Attachments\Tests\Support\AttachmentFixtures;

/*
| Reconocimiento del tipo real de un adjunto.
|
| Es la regla que justifica que el archivo pase por Fintegra en lugar de ir directo al
| almacenamiento, así que es lo primero que conviene tener cubierto: de aquí depende que un
| ejecutable renombrado no llegue nunca al disco.
*/

it('reconoce el formato por los bytes y no por la extensión', function (string $contenido, string $esperado) {
    $tipo = AttachmentContentType::resolve(
        AttachmentFixtures::of($contenido, 'documento'),
        AttachmentFixtures::allowed(),
    );

    expect($tipo->extension)->toBe($esperado);
})->with([
    'pdf' => [AttachmentFixtures::PDF, 'pdf'],
    'png' => [AttachmentFixtures::PNG, 'png'],
    'jpg' => [AttachmentFixtures::JPG, 'jpg'],
    'xls' => [AttachmentFixtures::XLS, 'xls'],
    'docx' => [AttachmentFixtures::ooxml('word/document.xml'), 'docx'],
    'xlsx' => [AttachmentFixtures::ooxml('xl/workbook.xml'), 'xlsx'],
    'pptx' => [AttachmentFixtures::ooxml('ppt/presentation.xml'), 'pptx'],
]);

/*
| La familia OOXML.
|
| docx, xlsx y pptx son ZIP por dentro, así que los tres empiezan por los mismos cuatro bytes.
| Mirar solo la firma los confunde entre sí — y confunde con ellos a cualquier otro ZIP.
*/

it('distingue entre sí a los formatos que comparten firma', function () {
    $docx = AttachmentFixtures::ooxml('word/document.xml');
    $xlsx = AttachmentFixtures::ooxml('xl/workbook.xml');

    // La premisa: por firma son indistinguibles.
    expect(substr($docx, 0, 4))->toBe(substr($xlsx, 0, 4));

    expect(AttachmentFixtures::allowed()->detect($docx))->toBe('docx')
        ->and(AttachmentFixtures::allowed()->detect($xlsx))->toBe('xlsx');
});

it('rechaza un zip corriente que se hace pasar por xlsx', function () {
    // El agujero contrario al del ejecutable: un ZIP cualquiera tiene la firma de un xlsx, así
    // que sin mirar dentro se guardaría como si fuera un libro de Excel.
    AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::ooxml('carpeta/cualquier-cosa.txt'), 'libro.xlsx'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class, 'su contenido no se reconoce como tal');

it('detecta el desacuerdo entre dos formatos de la misma familia', function () {
    // El caso que motivó todo esto, ahora con el mensaje correcto: antes decía que un docx
    // «es xlsx», porque xlsx era el único formato ZIP de la lista.
    AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::ooxml('word/document.xml'), 'libro.xlsx'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class, 'se envió como xlsx pero su contenido es docx');

/*
| Cabecera desplazada.
|
| La especificación del PDF no exige que `%PDF` esté en el byte cero, y los lectores de verdad
| lo buscan dentro del primer kilobyte. En la práctica llegan PDFs válidos con un BOM o un salto
| de línea delante, puestos ahí por un firmador o un conversor. Rechazarlos era demasiado
| estricto: el archivo no tiene nada de malo.
*/

it('acepta un pdf cuya cabecera no está en el byte cero', function (string $estorbo) {
    $tipo = AttachmentContentType::resolve(
        AttachmentFixtures::of($estorbo.AttachmentFixtures::PDF.' contenido', 'balance.pdf'),
        AttachmentFixtures::allowed(),
    );

    expect($tipo->extension)->toBe('pdf');
})->with([
    'BOM UTF-8' => [AttachmentFixtures::BOM],
    'salto de línea' => ["\n"],
    'espacios' => ['   '],
    'basura de un conversor' => ["\r\n\r\n"],
]);

it('no acepta la cabecera más allá de la ventana declarada', function () {
    // La tolerancia tiene fin: 1024 bytes. Más allá, el archivo no es un PDF con un estorbo
    // delante — es otra cosa que casualmente contiene «%PDF».
    AttachmentContentType::resolve(
        AttachmentFixtures::of(str_repeat('x', 2000).AttachmentFixtures::PDF, 'balance.pdf'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class);

it('no aplica la tolerancia a un formato que sí encajó por el byte cero', function () {
    // Un png que por casualidad lleva «%PDF» entre sus datos sigue siendo un png: la búsqueda
    // desplazada solo entra cuando NADA ha encajado al principio.
    $tipo = AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::PNG.'datos %PDF más datos', 'imagen.png'),
        AttachmentFixtures::allowed(),
    );

    expect($tipo->extension)->toBe('png');
});

/*
| Qué se le dice a quien envía.
|
| «No es de un formato permitido. Se aceptan: PDF…» a quien acaba de mandar un PDF no explica
| nada y manda a revisar lo que no es. Los dos fallos se parecen pero no son el mismo.
*/

it('distingue el contenido irreconocible del formato no admitido', function () {
    $formatoAdmitido = fn () => AttachmentContentType::resolve(
        AttachmentFixtures::of('esto no es ningún formato conocido', 'balance.pdf'),
        AttachmentFixtures::allowed(),
    );

    $formatoNoAdmitido = fn () => AttachmentContentType::resolve(
        AttachmentFixtures::of('esto no es ningún formato conocido', 'notas.txt'),
        AttachmentFixtures::allowed(),
    );

    // Declara un formato que sí se acepta → el problema es el archivo.
    expect($formatoAdmitido)->toThrow(
        UnsupportedAttachmentTypeException::class,
        'se envió como PDF, pero su contenido no se reconoce como tal'
    );

    // Declara un formato que no se acepta → el problema es el formato.
    expect($formatoNoAdmitido)->toThrow(
        UnsupportedAttachmentTypeException::class,
        'no es de un formato permitido'
    );
});

it('rechaza un ejecutable renombrado a pdf', function () {
    AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::EXE, 'inofensivo.pdf'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class, 'su contenido no se reconoce como tal');

it('rechaza un archivo cuyo contenido contradice la extensión declarada', function () {
    // El caso interesante: los bytes son de un formato PERMITIDO, pero no del que dice el
    // nombre. El desacuerdo es, en sí mismo, la señal.
    AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::PNG, 'contrato.pdf'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class, 'se envió como pdf pero su contenido es png');

it('acepta el archivo cuando el nombre no declara extensión', function () {
    // Sin extensión no hay nada que contradecir: mandan los bytes.
    $tipo = AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::PDF, 'contrato'),
        AttachmentFixtures::allowed(),
    );

    expect($tipo->extension)->toBe('pdf');
});

it('trata jpeg y jpg como el mismo formato', function () {
    $tipo = AttachmentContentType::resolve(
        AttachmentFixtures::of(AttachmentFixtures::JPG, 'cedula.JPEG'),
        AttachmentFixtures::allowed(),
    );

    expect($tipo->extension)->toBe('jpg');
});

it('rechaza un archivo más corto que la firma que dice tener', function () {
    // La firma de un png son 8 bytes; con menos no puede coincidir, y se rechaza en vez de
    // aceptarse por parecido.
    AttachmentContentType::resolve(
        AttachmentFixtures::of("\x89PNG", 'recortado.png'),
        AttachmentFixtures::allowed(),
    );
})->throws(UnsupportedAttachmentTypeException::class);

it('nombra los formatos aceptados en el mensaje de error', function () {
    expect(AttachmentFixtures::allowed()->label())->toBe('PDF, JPG, PNG, XLS, DOCX, XLSX, PPTX');
});

it('no reconoce nada cuando la firma está vacía', function () {
    expect(AttachmentFixtures::allowed()->detect(''))->toBeNull();
});

it('exige declarar al menos un formato permitido', function () {
    AllowedAttachmentTypes::fromMap([]);
})->throws(InvalidArgumentException::class);
