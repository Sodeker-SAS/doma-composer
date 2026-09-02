<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Http;

/**
 * Con qué `Content-Type` se sirve cada formato permitido.
 *
 * POR QUÉ VIVE EN EL MÓDULO Y NO EN CADA CONSUMIDOR: la extensión que se guarda no es la que
 * declaró quien subió el archivo, sino la que se dedujo de sus bytes —eso lo decide este
 * módulo—. Que la traducción a MIME esté al lado evita que cada consumidor se invente la suya y
 * que dos respondan cosas distintas para el mismo archivo.
 *
 * POR QUÉ ES UN MAPA EXPLÍCITO y no la tabla de tipos de Symfony: la lista de formatos
 * aceptados es corta y cerrada —la de `config/attachments.php`— y con un mapa propio se sabe
 * exactamente qué se responde. Una tabla general devuelve `application/octet-stream` para lo que
 * no reconoce, que es justo lo que aquí no debe pasar sin que nos enteremos.
 *
 * ESTÁ EN INFRASTRUCTURE/HTTP porque un tipo MIME solo tiene sentido al responder una petición.
 * El dominio habla de extensiones canónicas y no sabe que existe HTTP.
 */
final class AttachmentMimeTypes
{
    /**
     * Se corresponde una a una con las extensiones de `attachments.allowed_types`.
     *
     * AL AÑADIR UN FORMATO ALLÍ, AÑÁDELO AQUÍ: sin entrada se sirve como binario genérico, que
     * el navegador siempre descarga en lugar de mostrar.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Binario sin interpretar: el navegador lo descarga en vez de intentar mostrarlo. */
    public const FALLBACK = 'application/octet-stream';

    public static function forExtension(string $extension): string
    {
        return self::TYPES[strtolower(trim($extension))] ?? self::FALLBACK;
    }
}
