<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Infrastructure\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Sodeker\Attachments\Domain\Repositories\ResolvesTenantDiskInterface;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;
use Throwable;

/**
 * Resuelve el destino de los adjuntos leyendo `landlord.tenant_disks`.
 *
 * POR QUÉ NO SE HACE EN EL MIDDLEWARE: hay dos que fijan el tenant —`SetTenantConnection` para la
 * web y `ResolveExplicitTenantConnectionForApi` para la API— y el módulo puede usarse además
 * desde un trabajo en cola, donde no corre ninguno de los dos. Resolverlo aquí, partiendo de la
 * conexión que ya quedó apuntada, cubre los tres caminos sin duplicar código ni tocar la cadena
 * HTTP. Es el mismo criterio que sigue {@see TenantConnectionStorageTenantResolver} para la ruta.
 *
 * SIN FILA, TODO SIGUE COMO HASTA AHORA: un cliente que no declara destino usa el disco por
 * defecto de `config/attachments.php`. Una tabla vacía significa «todos en el disco de siempre»,
 * así que se puede desplegar sin cambiar el comportamiento de nadie.
 *
 * VARIOS DESTINOS POR CLIENTE: la restricción única de la tabla es `(tenant_id, purpose)`, no
 * `tenant_id` a secas, así que un mismo cliente puede declarar un destino por tipo de adjunto.
 * Se busca de lo específico a lo general en UNA sola consulta, y gana la fila más concreta.
 *
 * NO PARTICIPA EN LA LECTURA. Esta clase solo decide dónde se escribe algo NUEVO. Para volver a
 * leer un adjunto ya guardado se usa su `storage_location`, que viaja en la propia fila: por eso
 * cambiar aquí el destino de un cliente no afecta a nada de lo que ya está escrito.
 */
final class LandlordTenantDiskResolver implements ResolvesTenantDiskInterface
{
    /** Propósito comodín: cubre cualquier adjunto que no tenga una fila más específica. */
    private const FALLBACK_PURPOSE = 'attachments';

    /**
     * Memoria dentro de la misma petición, indexada por propósito.
     *
     * Se registra como `scoped` en el provider, no como singleton: en un proceso de larga vida
     * —una cola, Octane— un singleton arrastraría el destino de un cliente a la petición del
     * siguiente, que es exactamente el fallo que este módulo existe para evitar.
     *
     * @var array<string, AttachmentLocation>
     */
    private array $resolved = [];

    public function locationFor(string $purpose): AttachmentLocation
    {
        $purpose = trim($purpose);

        if (isset($this->resolved[$purpose])) {
            return $this->resolved[$purpose];
        }

        $row = $this->findConfiguredDisk($purpose);

        if ($row === null) {
            return $this->resolved[$purpose] = $this->defaultLocation();
        }

        try {
            return $this->resolved[$purpose] = $this->toLocation($row);
        } catch (InvalidArgumentException $e) {
            // Una fila mal declarada no debe tumbar la carga ni, peor, mandar el archivo a un
            // sitio inesperado: se cae al disco por defecto y queda constancia para corregirla.
            Log::warning("Destino de adjuntos mal declarado para «{$purpose}»; se usa el disco por defecto: ".$e->getMessage());

            return $this->resolved[$purpose] = $this->defaultLocation();
        }
    }

    /**
     * Fila activa del cliente al que apunta la conexión `tenant`, para ese propósito.
     *
     * SE BUSCA POR EL NOMBRE DE LA BASE y no por un id guardado en la petición porque es el único
     * dato que está disponible en los tres orígenes. Es el mismo anclaje que usa el resolvedor de
     * la ruta, así que ambos hablan siempre del mismo cliente.
     *
     * UNA SOLA CONSULTA PARA LOS DOS PROPÓSITOS: se piden ambos y se ordena poniendo primero el
     * específico. Así se evita el viaje extra a la base cuando el cliente no tiene fila propia
     * para ese tipo, que va a ser el caso habitual.
     */
    private function findConfiguredDisk(string $purpose): ?object
    {
        $database = trim((string) config('database.connections.tenant.database', ''));

        if ($database === '') {
            return null;
        }

        try {
            $row = DB::connection('landlord')
                ->table('tenant_disks')
                ->join('tenants', 'tenants.id', '=', 'tenant_disks.tenant_id')
                ->where('tenants.db_database', $database)
                ->whereIn('tenant_disks.purpose', array_unique([$purpose, self::FALLBACK_PURPOSE]))
                ->where('tenant_disks.status', '1')
                ->whereNull('tenant_disks.deleted_at')
                ->orderByRaw('case when tenant_disks.purpose = ? then 0 else 1 end', [$purpose])
                ->select('tenant_disks.driver', 'tenant_disks.config')
                ->first();
        } catch (Throwable $e) {
            // La tabla puede no existir todavía: el código se despliega antes de correr la
            // migración de landlord. Caer al disco por defecto es preferible a dejar la carga de
            // adjuntos rota durante esa ventana, pero queda registrado para que no pase inadvertido.
            Log::warning('No se pudo leer el destino del tenant; se usa el disco por defecto: '.$e->getMessage());

            return null;
        }

        return is_object($row) && $row->driver !== null ? $row : null;
    }

    /**
     * Traduce la fila al destino que describe.
     *
     * La columna `driver` dice CON QUÉ se guarda y el JSON de `config` dice DÓNDE. Para S3 ese
     * dónde es el bucket; para un disco local, el nombre del disco declarado en
     * `filesystems.php`. Las credenciales no salen de aquí: viven en la definición del driver,
     * que las lee del entorno, así que la base de datos nunca guarda secretos.
     */
    private function toLocation(object $row): AttachmentLocation
    {
        $driver = strtolower(trim((string) $row->driver));
        $config = $this->decodeConfig($row->config ?? null);

        if ($driver === AttachmentLocation::DRIVER_S3) {
            $bucket = trim((string) ($config['bucket'] ?? ''));

            if ($bucket === '') {
                throw new InvalidArgumentException('una fila con driver s3 debe declarar el bucket en su configuración.');
            }

            return AttachmentLocation::of(AttachmentLocation::DRIVER_S3, $bucket);
        }

        // Cualquier otro driver se interpreta como un disco declarado en `filesystems.php`. El
        // nombre puede venir en la configuración o, si no, es el propio valor de la columna: eso
        // cubre las filas que dicen simplemente `public`.
        $disk = trim((string) ($config['disk'] ?? $driver));

        // CÓMO SE DESCRIBE ESE DISCO LO DECIDE SU PROPIO DRIVER, no la columna de la fila. Un
        // disco local sigue siendo `local:` —las filas que ya existen no cambian de significado—
        // y cualquier otro (el NAS de un cliente por sftp, un ftp, un s3 con endpoint propio) se
        // describe como `disk:`, que es lo que de verdad es. Ver AttachmentLocation::DRIVER_DISK.
        //
        // UN DISCO NO DECLARADO CAE EN `disk:` a propósito: no se puede afirmar que sea local, y
        // de todos modos `FlysystemAttachmentBlobStorage` lo rechazará al no encontrar su
        // definición, que es donde ese error se explica bien.
        return config('filesystems.disks.'.$disk.'.driver') === AttachmentLocation::DRIVER_LOCAL
            ? AttachmentLocation::localDisk($disk)
            : AttachmentLocation::declaredDisk($disk);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(?string $json): array
    {
        $json = trim((string) $json);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        // Un JSON corrupto no debe cambiar el destino en silencio: se ignora y se usa la
        // definición base del driver, que es el comportamiento predecible.
        if (! is_array($decoded)) {
            Log::warning('La configuración del destino del tenant no es un JSON válido; se ignora.');

            return [];
        }

        return $decoded;
    }

    private function defaultLocation(): AttachmentLocation
    {
        return AttachmentLocation::localDisk((string) config('attachments.disk', 'public'));
    }
}
