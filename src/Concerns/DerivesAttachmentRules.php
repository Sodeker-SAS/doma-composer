<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Concerns;

/**
 * Reglas de validación derivadas de la configuración del módulo, para el FormRequest del
 * consumidor.
 *
 * POR QUÉ SE DERIVAN Y NO SE ESCRIBEN A MANO: si el FormRequest lista los formatos por su cuenta,
 * el día que la aplicación admita uno nuevo hay que acordarse de tocar los dos sitios, y el
 * síntoma de olvidarlo es que el módulo acepta un archivo que el formulario rechazó.
 *
 * QUIÉN DECIDE QUÉ, en dos niveles:
 *
 *   - LA APLICACIÓN declara en `config/attachments.php` todo lo que sabe manejar.
 *   - EL MÓDULO CONSUMIDOR estrecha esa lista con `only:` cuando su negocio lo pide. Un
 *     comprobante puede no querer imágenes aunque la aplicación las admita.
 *
 * SIN `only:` SE ACEPTA TODO lo que la aplicación permita. Es el valor por defecto a propósito:
 * un consumidor que no tenga una opinión sobre formatos no debería tener que expresarla, y
 * añadir un formato en la configuración llega solo a quien no lo restringió.
 *
 * LO QUE `only:` NO PUEDE HACER es ampliar. La intersección se calcula siempre contra lo que la
 * aplicación permite, y las extensiones de `blocked_extensions` quedan fuera pase lo que pase:
 * un módulo puede decidir que no quiere imágenes, nunca que sí quiere `.html`.
 *
 * LOS MENSAJES VIENEN CON LAS REGLAS. `attachmentFileMessages()` devuelve los textos en español
 * ya asociados a las reglas que emite `attachmentFileRules()`. No los escribas a mano en el
 * FormRequest: un mensaje personalizado se asocia por el nombre interno de la regla, y el día que
 * ese nombre cambie el mensaje deja de dispararse sin producir ningún error.
 *
 * ESTA VALIDACIÓN ES LA PRIMERA BARRERA, NO LA ÚNICA. Vive en el FormRequest, así que solo cubre
 * lo que entra por ahí; el módulo vuelve a validar el contenido en `store()`. Si un camino no
 * pasa por un FormRequest —una cola, un import—, la garantía la sigue dando el módulo.
 */
trait DerivesAttachmentRules
{
    /**
     * Reglas para un archivo del formulario.
     *
     * @param  list<string>|null  $only  Formatos que este módulo acepta. Null: todos los de la
     *                                   aplicación.
     * @return list<string>
     */
    protected function attachmentFileRules(?array $only = null): array
    {
        return [
            'file',
            'max:'.$this->attachmentMaxKilobytes(),
            $this->attachmentFormatRule().':'.implode(',', $this->allowedExtensions($only)),
        ];
    }

    /**
     * Mensajes en español para esas mismas reglas.
     *
     * POR QUÉ LOS DA EL TRAIT Y NO EL CONSUMIDOR: un mensaje personalizado se asocia por el
     * nombre de la regla (`attachments.*.extensions`), así que escribirlo en el FormRequest
     * obliga al consumidor a conocer un detalle interno de `attachmentFileRules()`. Cuando ese
     * detalle cambió —la regla pasó de `mimes` a `extensions`— los mensajes dejaron de
     * dispararse **sin ningún error**: una clave de mensaje que sobra simplemente no se usa, y
     * el usuario pasó a ver la clave de traducción en crudo. Derivándolos aquí, el nombre de la
     * regla vive en un solo sitio y los dos lados no pueden divergir.
     *
     * CÓMO SE USA, esparciéndolos y sobrescribiendo después lo que haga falta:
     *
     *     return [
     *         ...$this->attachmentFileMessages(),
     *         'attachments.required' => 'Debe adjuntar al menos un documento',
     *     ];
     *
     * SI PASAS `only:` A LAS REGLAS, PÁSALO TAMBIÉN AQUÍ: el texto enumera los formatos
     * aceptados, y con listas distintas el mensaje prometería algo que la regla rechaza.
     *
     * @param  string  $attribute  Campo al que se aplicaron las reglas, sin sufijo de regla.
     * @param  list<string>|null  $only  El mismo que se pasó a `attachmentFileRules()`.
     * @return array<string, string>
     */
    protected function attachmentFileMessages(string $attribute = 'attachments.*', ?array $only = null): array
    {
        return [
            $attribute.'.file' => 'El adjunto no es un archivo válido',
            $attribute.'.max' => 'Cada archivo no puede superar '.$this->attachmentMaxMegabytes().' MB',
            $attribute.'.'.$this->attachmentFormatRule() => 'Formato no permitido ('.implode(', ', $this->allowedExtensions($only)).')',
        ];
    }

    /**
     * La regla con la que se validan los formatos, en un solo sitio.
     *
     * `extensions` valida el nombre del archivo y no el MIME adivinado del contenido. Es lo
     * correcto aquí porque los tipos sin firma —un `.log`, un `.dump`— no tienen un MIME estable
     * que `mimes` pueda reconocer, y el contenido ya lo verifica el módulo con los magic bytes,
     * que es una comprobación más fuerte que la del validador.
     */
    private function attachmentFormatRule(): string
    {
        return 'extensions';
    }

    /**
     * Extensiones aceptadas, ya intersectadas con lo que pida el consumidor.
     *
     * @param  list<string>|null  $only
     * @return list<string>
     */
    protected function allowedExtensions(?array $only = null): array
    {
        $canonical = array_keys((array) config('attachments.allowed_types', []));
        $aliases = array_keys((array) config('attachments.extension_aliases', []));
        $opaque = (array) config('attachments.opaque_types', []);
        $blocked = (array) config('attachments.blocked_extensions', []);

        /** @var list<string> $equivalents */
        $equivalents = array_merge([], ...array_values(
            (array) config('attachments.equivalent_declarations', [])
        ));

        $normalize = static fn (array $list): array => array_values(array_unique(array_map(
            static fn ($extension): string => strtolower(trim((string) $extension)),
            $list,
        )));

        $extensions = $normalize([...$canonical, ...$aliases, ...$opaque, ...$equivalents]);

        // El bloqueo se aplica SIEMPRE y al final: ni la configuración de la aplicación ni la
        // petición del consumidor pueden devolver una extensión bloqueada a la lista.
        $extensions = array_diff($extensions, $normalize($blocked));

        if ($only !== null) {
            $extensions = array_intersect($extensions, $normalize($only));
        }

        $extensions = array_values($extensions);
        sort($extensions);

        return $extensions;
    }

    protected function attachmentMaxKilobytes(): int
    {
        return (int) round(((int) config('attachments.max_size_bytes', 52428800)) / 1024);
    }

    protected function attachmentMaxMegabytes(): string
    {
        return (string) round($this->attachmentMaxKilobytes() / 1024);
    }
}
