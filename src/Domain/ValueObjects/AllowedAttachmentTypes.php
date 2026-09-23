<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Política de tipos admitidos: qué formatos acepta el sistema y cómo se reconoce cada uno.
 *
 * POR QUÉ ES UN OBJETO Y NO UNA LECTURA DE `config()`: el dominio no importa el framework —la
 * misma razón por la que la entidad `Study` recibe el uuid ya generado en lugar de llamar a
 * `Str::ulid()`—. La capa de Application lee la configuración y construye esta política; el
 * dominio solo la usa. De paso queda trivial de probar: se instancia con dos formatos de
 * juguete y no hace falta tocar `config/`.
 */
final readonly class AllowedAttachmentTypes
{
    /**
     * @param  array<string, string>  $signatures  extensión canónica → magic bytes del formato
     * @param  array<string, string>  $aliases  extensión alterna → extensión canónica
     * @param  array<string, string>  $containerMarkers  extensión canónica → cadena que la identifica dentro del contenedor
     */
    private function __construct(
        private array $signatures,
        private array $aliases,
        private array $containerMarkers,
        private array $searchWindows,
        /** @var array<string, list<string>> detectado => declaraciones equivalentes aceptadas */
        private array $equivalents,
        /** @var list<string> extensiones aceptadas sin verificar el contenido */
        private array $opaque,
        /** @var list<string> extensiones rechazadas siempre */
        private array $blocked,
    ) {}

    /**
     * @param  array<string, string>  $signatures
     * @param  array<string, string>  $aliases
     * @param  array<string, string>  $containerMarkers
     * @param  array<string, int>  $searchWindows  extensión → bytes iniciales donde se admite hallar la firma
     */
    public static function fromMap(
        array $signatures,
        array $aliases = [],
        array $containerMarkers = [],
        array $searchWindows = [],
        array $equivalents = [],
        array $opaque = [],
        array $blocked = [],
    ): self {
        if ($signatures === [] && $opaque === []) {
            throw new InvalidArgumentException('Debe declararse al menos un tipo de adjunto permitido.');
        }

        $normalize = static fn (array $list): array => array_values(array_unique(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $list,
        )));

        return new self(
            $signatures,
            $aliases,
            $containerMarkers,
            $searchWindows,
            array_map($normalize, $equivalents),
            $normalize($opaque),
            $normalize($blocked),
        );
    }

    /**
     * Extensión rechazada siempre, antes de mirar el contenido.
     *
     * NO SE CONSULTA LA LISTA DEL CONSUMIDOR: un módulo puede estrechar lo que acepta, nunca
     * ampliarlo hacia aquí. Ver la nota de `blocked_extensions` en la configuración.
     */
    public function isBlocked(string $extension): bool
    {
        return in_array($this->canonical($extension), $this->blocked, true);
    }

    /** Extensión aceptada por su nombre porque no existe firma contra la que contrastarla. */
    public function isOpaque(string $extension): bool
    {
        $extension = $this->canonical($extension);

        return $extension !== '' && in_array($extension, $this->opaque, true);
    }

    /**
     * ¿`$declared` es una forma equivalente de `$detected`?
     *
     * Cubre los subtipos que comparten formato interno con un tipo verificado —un xlsm es un
     * xlsx con macros— y que los magic bytes no pueden separar.
     */
    public function acceptsDeclarationFor(string $detected, string $declared): bool
    {
        return in_array($this->canonical($declared), $this->equivalents[$detected] ?? [], true);
    }

    /** ¿Esta extensión canónica es uno de los formatos que se aceptan? */
    public function supports(string $extension): bool
    {
        $extension = $this->canonical($extension);

        if (array_key_exists($extension, $this->signatures) || in_array($extension, $this->opaque, true)) {
            return true;
        }

        foreach ($this->equivalents as $declarations) {
            if (in_array($extension, $declarations, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Todas las extensiones que el módulo aceptaría, sin las bloqueadas.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $extensions = [
            ...array_keys($this->signatures),
            ...$this->opaque,
            ...array_merge([], ...array_values($this->equivalents)),
        ];

        // SE CONSERVA EL ORDEN DE LA CONFIGURACIÓN, no se ordena alfabéticamente: la lista está
        // curada —los formatos habituales primero— y es la que ve el usuario en el mensaje de
        // error cuando su archivo no encaja.
        $extensions = array_values(array_unique(array_map(
            static fn ($value): string => strtolower((string) $value),
            $extensions,
        )));

        return array_values(array_diff($extensions, $this->blocked));
    }

    /**
     * Extensión canónica del formato que encabeza estos bytes, o null si ninguno encaja.
     *
     * POR QUÉ NO BASTA CON LA FIRMA: hay familias de formatos que comparten los primeros bytes
     * porque comparten envoltorio. docx, xlsx y pptx son OOXML, que por dentro es un ZIP, así
     * que los tres empiezan igual: la firma solo alcanza a decir «esto es un contenedor».
     *
     * Cuando eso pasa, se mira qué lleva dentro. Los nombres de las entradas de un ZIP viajan
     * SIN comprimir en sus cabeceras, así que el marcador se busca directamente en el trozo de
     * archivo que se recibe — no hace falta descomprimir nada.
     *
     * Y si es un contenedor que no trae NINGUNO de los marcadores conocidos, se devuelve null:
     * un `.zip` cualquiera renombrado a `.xlsx` se rechaza en vez de colarse como libro de
     * Excel, que es exactamente el agujero que este método existe para tapar.
     *
     * @param  string  $head  Cabecera del archivo. Debe ser lo bastante larga como para que
     *                        quepan los marcadores, no solo los magic bytes.
     */
    public function detect(string $head): ?string
    {
        /** @var list<string> $candidates */
        $candidates = [];

        foreach ($this->signatures as $extension => $magicBytes) {
            if ($magicBytes !== '' && str_starts_with($head, (string) $magicBytes)) {
                $candidates[] = (string) $extension;
            }
        }

        if ($candidates !== []) {
            // Firma no ambigua: un solo formato la declara y no hay nada que desempatar.
            return count($candidates) === 1 ? $candidates[0] : $this->disambiguate($head, $candidates);
        }

        return $this->detectDisplaced($head);
    }

    /**
     * Segunda vuelta, solo para los formatos que admiten la cabecera desplazada.
     *
     * POR QUÉ EN UNA SEGUNDA VUELTA Y NO EN LA PRIMERA: buscar una firma en vez de exigirla al
     * principio abre la puerta a falsos positivos —los cuatro bytes de `%PDF` pueden aparecer
     * por casualidad dentro de los datos comprimidos de un png—. Dejándolo para cuando NINGÚN
     * formato ha encajado por el byte cero, un archivo que sí es lo que dice nunca llega aquí, y
     * la tolerancia solo se aplica a lo que de otro modo se habría rechazado.
     */
    private function detectDisplaced(string $head): ?string
    {
        foreach ($this->signatures as $extension => $magicBytes) {
            $window = (int) ($this->searchWindows[(string) $extension] ?? 0);

            if ($magicBytes === '' || $window <= 0) {
                continue;
            }

            $position = strpos($head, (string) $magicBytes);

            if ($position !== false && $position < $window) {
                return (string) $extension;
            }
        }

        return null;
    }

    /**
     * Varios formatos comparten firma: son contenedores y solo se distinguen por dentro.
     *
     * @param  list<string>  $candidates
     */
    private function disambiguate(string $head, array $candidates): ?string
    {
        foreach ($candidates as $extension) {
            $marker = $this->containerMarkers[$extension] ?? '';

            if ($marker !== '' && str_contains($head, $marker)) {
                return $extension;
            }
        }

        return null;
    }

    /** Resuelve los alias de extensión: "jpeg" y "jpg" son el mismo formato. */
    public function canonical(string $extension): string
    {
        $extension = strtolower(trim($extension));

        return $this->aliases[$extension] ?? $extension;
    }

    /** Lista legible de formatos aceptados, para los mensajes de error del consumidor. */
    public function label(): string
    {
        return strtoupper(implode(', ', $this->all()));
    }
}
