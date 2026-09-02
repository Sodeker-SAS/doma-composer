<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Domain\Repositories;

use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentOwner;

/**
 * Puerto que responde «¿dónde se guardan LOS ADJUNTOS DE ESTE TIPO de ESTE cliente?».
 *
 * POR QUÉ NO BASTA `config('attachments.disk')`: ese valor es uno solo para todo el despliegue.
 * En cuanto un cliente guarda en su propio bucket y otro sigue en disco local, el destino deja de
 * ser una constante de configuración y pasa a ser un dato del cliente.
 *
 * POR QUÉ RECIBE UN PROPÓSITO Y NO NADA: tampoco basta con un destino por cliente. Dentro de un
 * mismo estudio, los documentos de los aplicantes pueden ir a un bucket mientras los del inmueble
 * se quedan en el disco propio. El propósito es la clave que distingue esos destinos, y sale de
 * las coordenadas del dueño — ver {@see AttachmentOwner::purpose()}.
 *
 * POR QUÉ ES UN PUERTO Y NO UNA CONSULTA DIRECTA: el módulo no tiene por qué saber que esa
 * configuración vive hoy en `landlord.tenant_disks`. Mañana puede llegar de Doma por HTTP, o de
 * un servicio central de adjuntos; eso es cambiar la implementación, no el dominio.
 *
 * ES UN PUERTO APARTE DE {@see ResolvesStorageTenantInterface} a propósito: uno responde «de qué
 * cliente es» —para segmentar la ruta— y este «dónde escribe ese cliente». Son dos preguntas
 * distintas, con respuestas que pueden venir de fuentes distintas.
 */
interface ResolvesTenantDiskInterface
{
    /**
     * Ubicación donde deben escribirse los adjuntos de ese propósito.
     *
     * LA BÚSQUEDA VA DE LO ESPECÍFICO A LO GENERAL: primero una fila declarada para ese propósito
     * exacto; si no existe, la fila comodín del cliente; y si tampoco, el disco por defecto de la
     * configuración. Esa cadena es lo que permite añadir destinos finos sin tocar a los
     * consumidores que no saben nada de propósitos: los suyos caen en el comodín y siguen
     * escribiendo donde siempre.
     *
     * DEVUELVE UNA UBICACIÓN Y NO UN NOMBRE DE DISCO porque es lo que se persiste con el adjunto:
     * un nombre se re-vincula cuando cambia la configuración, una ubicación no.
     */
    public function locationFor(string $purpose): AttachmentLocation;
}
