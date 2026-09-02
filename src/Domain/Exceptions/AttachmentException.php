<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Exceptions;

use Exception;

/**
 * Base de los fallos del módulo de adjuntos.
 *
 * MISMO CRITERIO QUE `StudyConflictException`: una excepción por regla en lugar de un
 * `RuntimeException` pelado que el controlador tenga que adivinar. Cada subclase decide si es
 * culpa del consumidor —envió un formato que no aceptamos— o del sistema —no se pudo escribir—,
 * y el adaptador HTTP traduce eso a un status. El dominio no sabe de códigos HTTP.
 */
abstract class AttachmentException extends Exception
{
    /**
     * ¿El consumidor puede corregirlo reenviando algo distinto?
     *
     * true  → es un problema del envío (formato, tamaño): 422 con el detalle.
     * false → es un problema nuestro (disco, red): 500 genérico y traza al log.
     */
    abstract public function isCallerFault(): bool;
}
