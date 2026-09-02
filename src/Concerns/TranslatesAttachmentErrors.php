<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Sodeker\Attachments\Domain\Exceptions\AttachmentException;

/**
 * Traducción de los fallos de adjuntos para controladores web (Inertia) y API (JSON).
 *
 * EXISTE PARA QUE UNA EXCEPCIÓN DEL MÓDULO NO SUBA SIN TOCAR hasta el manejador global. Su
 * mensaje puede incluir la clave completa del objeto —nombre de la base del tenant y estructura
 * de carpetas— y el destino de almacenamiento, datos que no deben publicarse nunca.
 *
 * EN WEB SE RESPONDE CON REDIRECT + `withErrors`, NO CON 422. Inertia solo invoca el `onError`
 * del frontend cuando la respuesta trae bolsa de errores, y ese callback es el que muestra el
 * SweetAlert que la vista ya tiene escrito. Un 422 serviría igual de lejos, pero MENTIRÍA: le
 * diría al usuario «corrige el envío y reintenta» cuando el envío era correcto y lo que está
 * mal es la configuración del destino. El 302 no afirma de quién es la culpa.
 *
 * EN API SE RESPONDE JSON porque los caminos que suben adjuntos por XHR esperan ese transporte:
 * 422 cuando el envío sí se puede corregir y 500 cuando el fallo pertenece al sistema. El
 * transporte cambia; la política de qué se cuenta, no.
 *
 * VIVE EN UN TRAIT COMPARTIDO porque todos los controladores que suben adjuntos necesitan
 * exactamente la misma decisión, y tenerla escrita varias veces es como empieza a divergir.
 *
 * QUÉ MENSAJE VE EL USUARIO, lo decide la propia excepción a través de `isCallerFault()`:
 *
 *   true  → el motivo literal: formato no permitido, archivo demasiado grande. Es accionable.
 *   false → un texto genérico. El detalle va al log, que es donde puede leerlo quien sí
 *           puede arreglarlo —el destino mal declarado, el NAS que no responde—. Ver
 *           UnknownAttachmentDiskException.
 */
trait TranslatesAttachmentErrors
{
    /**
     * Texto para los fallos que el usuario no puede corregir.
     *
     * DICE QUE QUEDÓ REGISTRADO a propósito: sin esa frase, quien ve «no fue posible» dos veces
     * seguidas no tiene forma de saber si vale la pena avisar a alguien.
     */
    private const ATTACHMENT_SYSTEM_FAILURE_MESSAGE = 'No fue posible guardar el documento. El detalle quedó registrado para el equipo técnico.';

    /**
     * Aplica una sola vez la política de exposición y devuelve el mensaje seguro.
     *
     * @param  string  $logContext  Acción que falló, para poder encontrarla en el log.
     */
    private function attachmentFailureMessage(AttachmentException $e, string $logContext): string
    {
        if ($e->isCallerFault()) {
            return $e->getMessage();
        }

        // La traza completa —incluida la causa encadenada, que es donde viaja el motivo real
        // cuando `AttachmentStorageFailedException` envuelve a otra— solo aquí.
        Log::error($logContext.' · '.$e->getMessage(), ['exception' => $e]);

        return self::ATTACHMENT_SYSTEM_FAILURE_MESSAGE;
    }

    /**
     * Devuelve a la vista anterior con el error en la bolsa que Inertia comparte.
     *
     * @param  string  $field  Clave de la bolsa de errores. Solo importa para que el frontend
     *                         pueda mostrarla junto al campo si algún día lo hace; hoy las
     *                         vistas toman el primer valor sin mirar la clave.
     */
    private function attachmentFailureRedirect(
        AttachmentException $e,
        string $logContext,
        string $field = 'attachments',
    ): RedirectResponse {
        return back()->withErrors([
            $field => $this->attachmentFailureMessage($e, $logContext),
        ]);
    }

    /**
     * Devuelve JSON para consumidores XHR: 422 para un envío corregible y 500 para el sistema.
     */
    private function attachmentFailureResponse(AttachmentException $e, string $logContext): JsonResponse
    {
        $status = $e->isCallerFault() ? 422 : 500;

        return response()->json([
            'message' => $this->attachmentFailureMessage($e, $logContext),
        ], $status);
    }
}
