<?php

declare(strict_types=1);

use Sodeker\Attachments\Concerns\DerivesAttachmentRules;
use Sodeker\Attachments\Tests\TestCase;

/*
| Las reglas y los mensajes que el trait entrega al FormRequest del consumidor.
|
| POR QUÉ EXISTE ESTE ARCHIVO: el trait no tenía pruebas, y eso permitió que al cambiar la regla
| de `mimes` a `extensions` los mensajes personalizados de las aplicaciones dejaran de dispararse
| en silencio —una clave de mensaje que sobra no falla, simplemente no se usa—. El usuario pasó a
| ver `validation.extensions` en crudo. La prueba que lo habría atrapado es la última de este
| archivo, y es la razón principal de que el trait ahora entregue también los mensajes.
|
| Va contra `config/attachments.php` de verdad, no contra una política de juguete: así avisa
| también si alguien mueve una extensión de categoría sin querer.
*/

uses(TestCase::class);

beforeEach(function () {
    // El trait está pensado para un FormRequest, pero no depende de nada suyo: solo de `config()`.
    // Una clase anónima basta y deja la prueba sin infraestructura HTTP.
    $this->consumidor = new class
    {
        use DerivesAttachmentRules;

        /**
         * @param  list<string>|null  $only
         * @return list<string>
         */
        public function reglas(?array $only = null): array
        {
            return $this->attachmentFileRules($only);
        }

        /**
         * @param  list<string>|null  $only
         * @return array<string, string>
         */
        public function mensajes(string $attribute = 'attachments.*', ?array $only = null): array
        {
            return $this->attachmentFileMessages($attribute, $only);
        }

        /**
         * @param  list<string>|null  $only
         * @return list<string>
         */
        public function extensiones(?array $only = null): array
        {
            return $this->allowedExtensions($only);
        }
    };
});

/*
| Composición de la lista.
*/

it('reúne las cuatro categorías que la aplicación sabe manejar', function () {
    $extensiones = $this->consumidor->extensiones();

    expect($extensiones)
        ->toContain('pdf')    // verificado por firma
        ->toContain('jpeg')   // alias de `jpg`
        ->toContain('txt')    // opaco
        ->toContain('xlsm');  // declaración equivalente de `xlsx`
});

it('deja fuera las extensiones bloqueadas', function () {
    $extensiones = $this->consumidor->extensiones();

    expect($extensiones)
        ->not->toContain('svg')
        ->not->toContain('html')
        ->not->toContain('php');
});

/*
| `only:`, la restricción por módulo.
|
| La propiedad que importa es que sea una INTERSECCIÓN y no una sustitución: un módulo decide
| qué quiere de lo que la aplicación ofrece, nunca lo que la aplicación no ofrece.
*/

it('estrecha la lista cuando el módulo declara `only`', function () {
    $extensiones = $this->consumidor->extensiones(['pdf', 'xlsx']);

    expect($extensiones)->toBe(['pdf', 'xlsx']);
});

it('no amplía: lo que la aplicación no permite sigue fuera aunque el módulo lo pida', function () {
    $extensiones = $this->consumidor->extensiones(['pdf', 'exe', 'dmg']);

    expect($extensiones)->toBe(['pdf']);
});

it('no deja que `only` reviva una extensión bloqueada', function () {
    $extensiones = $this->consumidor->extensiones(['pdf', 'svg', 'html']);

    expect($extensiones)->toBe(['pdf']);
});

it('acepta la declaración en mayúsculas o con espacios', function () {
    $extensiones = $this->consumidor->extensiones([' PDF ', 'Xlsx']);

    expect($extensiones)->toBe(['pdf', 'xlsx']);
});

/*
| Las reglas.
*/

it('deriva el tope de tamaño de la configuración, en kilobytes', function () {
    config()->set('attachments.max_size_bytes', 10 * 1024 * 1024);

    expect($this->consumidor->reglas())->toContain('max:10240');
});

it('valida el formato por la extensión y no por el MIME adivinado', function () {
    $reglas = $this->consumidor->reglas(['pdf', 'csv']);

    expect($reglas)->toBe(['file', 'max:51200', 'extensions:csv,pdf']);
});

/*
| Los mensajes.
|
| ESTA ES LA PRUEBA QUE FALTABA. No comprueba el texto —eso cambia— sino que la clave de cada
| mensaje corresponda a una regla realmente emitida. Es lo único que detecta el fallo silencioso:
| renombrar la regla y dejar el mensaje escuchando a la anterior.
*/

it('nombra cada mensaje con una regla que las reglas emiten de verdad', function () {
    $emitidas = array_map(
        static fn (string $regla): string => explode(':', $regla)[0],
        $this->consumidor->reglas(),
    );

    foreach (array_keys($this->consumidor->mensajes()) as $clave) {
        $regla = substr($clave, (int) strrpos($clave, '.') + 1);

        expect(in_array($regla, $emitidas, true))
            ->toBeTrue("El mensaje `{$clave}` escucha la regla `{$regla}`, que las reglas no emiten.");
    }
});

it('enumera en el mensaje los mismos formatos que la regla acepta', function () {
    $mensajes = $this->consumidor->mensajes(only: ['pdf', 'csv']);

    expect($mensajes['attachments.*.extensions'])->toBe('Formato no permitido (csv, pdf)');
});

it('anuncia el mismo tope que valida la regla', function () {
    config()->set('attachments.max_size_bytes', 10 * 1024 * 1024);

    expect($this->consumidor->mensajes()['attachments.*.max'])
        ->toBe('Cada archivo no puede superar 10 MB');
});

it('se adapta al campo que use el consumidor', function () {
    $mensajes = $this->consumidor->mensajes('documento');

    expect(array_keys($mensajes))->toBe([
        'documento.file',
        'documento.max',
        'documento.extensions',
    ]);
});
