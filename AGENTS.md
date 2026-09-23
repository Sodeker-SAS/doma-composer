# Guía para consumir `sodeker/laravel-attachments` desde un módulo

Instrucciones para un agente de IA que deba **integrar adjuntos en un módulo de negocio**
(Estudios, Seguimientos, Vouchers, Contratos…). No describe cómo modificar el paquete, sino cómo
usarlo desde fuera.

El ejemplo de referencia a lo largo del documento es el módulo **Estudios** de FINTEGRA, que es
la integración madura y la que debes imitar.

---

## 1. Regla de oro

> **Un módulo consumidor depende de DOS contratos y de un puñado de value objects. Nunca de
> `Infrastructure/`, nunca del modelo Eloquent, nunca de la tabla de adjuntos.**

| Puedes importar | Para qué |
|---|---|
| `Contracts\AttachmentStoragePort` | Escribir, leer, borrar y generar URL del archivo |
| `Contracts\AttachmentRegistryPort` | Registrar, consultar y dar de baja la ficha |
| `Domain\ValueObjects\AttachmentBinary` | El contenido que entregas |
| `Domain\ValueObjects\AttachmentOwner` | De quién es el adjunto |
| `Domain\ValueObjects\StoredAttachment` | Lo que recibes al escribir |
| `Domain\Entities\Attachment` | Lo que recibes al consultar |
| `Domain\Exceptions\*` | Para traducir fallos a HTTP |
| `Concerns\*` | Traits de apoyo (validación, compensación, errores) |
| `Infrastructure\Http\UploadedFileAttachmentFactory` | Única excepción: traduce `UploadedFile` |

**Prohibido importar**: `Infrastructure\Database\*`, `Infrastructure\Storage\*`,
`Infrastructure\Tenancy\*`, `Application\Services\*`. Son detalles internos que cambian en
versiones PARCHE.

Ambos contratos se resuelven por inyección de constructor. El provider del paquete ya los enlaza:

```php
public function __construct(
    private readonly AttachmentStoragePort $attachments,
    private readonly AttachmentRegistryPort $registry,
) {}
```

---

## 2. El modelo de dos tablas

El paquete guarda **el archivo y sus metadatos**. No sabe qué es un estudio, un voucher ni un
contrato. La relación con tu negocio es **tuya** y vive en una tabla pivote que tú creas.

```
attachments (del paquete)          fin_study_attachments (tuya)
─────────────────────────          ────────────────────────────
id                        ◄─────── attachment_id
uuid                               study_id
name                               study_applicant_id
storage_location                   is_private
url  ← LA CLAVE, no una URL        created_by / updated_by
extension / size / status          deleted_at
```

Reglas que se derivan de esto:

- **Tu pivote guarda `attachment_id` y nada más del archivo.** Ni la ruta, ni el disco, ni el
  tamaño. Duplicar esos datos es el error más común: quedan desincronizados en cuanto el módulo
  cambia algo.
- **`attachments.url` contiene la CLAVE de almacenamiento, no un enlace.** El nombre de la
  columna es histórico. Se lee con `$attachment->url()` y se pasa tal cual a los métodos del
  puerto de almacenamiento.
- **Los permisos son tuyos.** El paquete no sabe de quién es un adjunto: `find()` devuelve
  cualquier adjunto vigente por uuid. Comprobar que ese adjunto pertenece a *tu* entidad es
  responsabilidad de tu servicio, y debe hacerse **antes** de leer o borrar.

---

## 3. Flujo de escritura

Es el flujo con más reglas. El orden importa.

```php
final class RegisterStudyDocumentsService
{
    use DiscardsStoredAttachments;

    private const OWNER_MODULE = 'studies';
    private const OWNER_ENTITY = 'study';

    public function execute(/* … */ array $documents, string $actorUuid): array
    {
        // 1. Valida TU negocio antes de escribir nada. Si el destinatario no es válido, no
        //    tiene sentido haber subido archivos que luego habría que borrar.
        $studyId = /* … */;
        $applicantId = $this->resolveApplicantId(/* … */);

        // 2. Describe al dueño. El ÁMBITO (4.º argumento) hace dos cosas de una: segmenta la
        //    ruta física y decide el destino de almacenamiento, porque de él sale el propósito
        //    con el que se busca el disco del cliente. Ver AttachmentOwner::purpose().
        $owner = AttachmentOwner::for(
            self::OWNER_MODULE,          // slug del módulo:  "studies"
            self::OWNER_ENTITY,          // slug de la entidad: "study"
            $studyId,                    // id del registro dueño (> 0)
            StudyDocumentCategory::orDefault($category),  // ámbito, opcional
        );

        /** @var list<StoredAttachment> $stored */
        $stored = [];

        try {
            // 3. ESCRIBE FUERA DE LA TRANSACCIÓN. Mantener abierta una transacción mientras se
            //    negocia con S3 retiene locks durante segundos por causas ajenas a la base.
            foreach ($documents as $binary) {
                $stored[] = $this->attachments->store($binary, $owner);
            }

            // 4. REGISTRA DENTRO DE LA TRANSACCIÓN, junto con tu pivote, para que aparezcan
            //    las dos filas o ninguna.
            $result = DB::connection($this->tenantDatabaseConnection())->transaction(
                function () use ($stored, $studyId, $applicantId, $actorUuid): array {
                    $created = [];

                    foreach ($stored as $attachment) {
                        $registered = $this->registry->register($attachment, $actorUuid);

                        $link = $this->linkAttachment->handle(new CreateStudyAttachmentCommand(
                            attachmentId: (int) $registered->id(),
                            studyId: $studyId,
                            studyApplicantId: $applicantId,
                            createdBy: $actorUuid,
                            updatedBy: $actorUuid,
                        ));

                        $created[] = /* tu DTO */;
                    }

                    return $created;
                }
            );
        } catch (Throwable $e) {
            // 5. COMPENSA. Si la transacción se deshizo, los bytes quedaron huérfanos y NO se
            //    pueden recuperar después: la tabla genérica no sabe de quién era el archivo.
            $this->discardStoredAttachments($this->attachments, $stored);

            throw $e;
        } finally {
            // 6. CIERRA LOS BINARIOS. `store()` cierra los que llegó a ver, incluso al
            //    rechazarlos, pero un fallo en el archivo N deja abiertos los de N en adelante.
            //    `close()` es idempotente, así que esto es inofensivo en el camino feliz.
            $this->closeAll($documents);
        }

        return $result;
    }
}
```

### Por qué escribir fuera y registrar dentro

Son dos contratos separados precisamente para permitir esto. Fundirlos obligaría a elegir entre
subir dentro de la transacción (locks largos) o escribir la fila fuera (sin atomicidad con tu
pivote). Se prefiere la **compensación explícita**: el fallo es raro y limpiarlo cuesta un
borrado, mientras que la transacción larga se paga en cada petición.

### Construir el `AttachmentBinary`

En la frontera HTTP, nunca en el servicio de aplicación:

```php
// En el controlador
public function store(StoreStudyDocumentRequest $request, UploadedFileAttachmentFactory $binaries)
{
    $documents = array_map(
        fn (UploadedFile $file) => $binaries->fromUploadedFile($file),
        $request->file('documents', []),
    );

    return $this->service->execute(/* … */, $documents, /* … */);
}
```

Desde otro origen (una cola, un import, un servicio externo) se construye a mano:

```php
AttachmentBinary::fromStream($stream, $originalName, $sizeBytes);
```

El puerto habla de **contenido**, no de peticiones HTTP. No añadas un método que reciba
`UploadedFile`: eso ataría el contrato a un único origen.

---

## 4. Flujo de lectura

```php
public function execute(string $studyUuid, string $attachmentUuid): StudyDocumentFileDTO
{
    $study = $this->studies->findByUuid($studyUuid);
    if ($study === null) {
        throw StudyNotFoundException::withUuid($studyUuid);
    }

    // 1. Ficha del adjunto: aquí viven la ubicación y la clave.
    $attachment = $this->registry->find($attachmentUuid);
    if ($attachment === null) {
        throw StudyDocumentNotFoundException::forStudy($studyUuid, $attachmentUuid);
    }

    // 2. AUTORIZACIÓN: comprobar en TU pivote que el adjunto es de ESTE estudio.
    //    Sin esto, cualquiera con un uuid válido lee cualquier documento del sistema.
    $link = $this->links->findLink((int) $study->id(), (int) $attachment->id());
    if ($link === null) {
        throw StudyDocumentNotFoundException::forStudy($studyUuid, $attachmentUuid);
    }

    return new StudyDocumentFileDTO(
        // Dónde quedó al escribirlo, NO dónde escribe hoy el cliente: es lo que permite seguir
        // leyendo lo antiguo después de cambiarle el destino a un tenant.
        storageLocation: $attachment->storageLocation(),
        storageKey: $attachment->url(),
        // …
    );
}
```

Con esos dos valores, para servir el archivo:

```php
// Camino controlado: la aplicación hace el viaje al almacenamiento y puede comprobar
// quién pregunta antes. Es el único correcto para documentos privados.
$stream = $this->attachments->readStream($storageLocation, $storageKey);

// Camino por URL: el navegador va solo, sin sesión. Sobre disco local nginx lo sirve sin
// pasar por Laravel, así que cualquiera con el enlace lo lee.
$url = $this->attachments->url($storageLocation, $storageKey, expiresInMinutes: 5);
```

**Nunca persistas la URL.** Deja de servir en cuanto cambia el almacenamiento, y para un
documento privado nunca debió existir. Se genera al momento, siempre.

---

## 5. Flujo de borrado

Baja lógica en las dos tablas, dentro de una transacción. **No borra el objeto físico.**

```php
public function execute(string $studyUuid, string $attachmentUuid, string $actorUuid): void
{
    // Reutiliza el servicio de lectura: la comprobación de pertenencia es la misma y no puede
    // quedarse fuera del borrado.
    $document = $this->resolveDocument->execute($studyUuid, $attachmentUuid);

    DB::connection($this->tenantDatabaseConnection())->transaction(function () use ($document, $actorUuid): void {
        // Las dos bajas van juntas o ninguna. Solo el vínculo dejaría un adjunto vigente que
        // nadie apunta; solo el adjunto, un estudio mostrando un documento que no se abre.
        $this->links->unlink($document->linkId, $actorUuid);
        $this->registry->forget($document->attachmentUuid);
    });
}
```

**No llames a `AttachmentStoragePort::delete()` en el borrado de negocio.** Ese método es para
dos casos: la compensación inmediata (`DiscardsStoredAttachments`) y un comando programado que
recupere espacio de filas dadas de baja hace tiempo — fuera de una petición, con plazo de gracia.
Borrar el objeto durante un `DELETE` de usuario es irreversible y no se puede deshacer.

---

## 6. Errores

Todas las excepciones del módulo heredan de `AttachmentException`:

| Excepción | Cuándo |
|---|---|
| `UnsupportedAttachmentTypeException` | Formato no permitido o incoherente con el contenido real |
| `AttachmentTooLargeException` | Excede `max_size_bytes` |
| `AttachmentStorageFailedException` | Fallo escribiendo en el almacenamiento |
| `UnknownAttachmentDiskException` | Destino declarado que no existe en `filesystems.disks` |

Usa el trait `TranslatesAttachmentErrors` en tu controlador en vez de escribir el mapeo:

```php
use TranslatesAttachmentErrors;

// API
return $this->attachmentFailureResponse($e, 'carga de documentos del estudio');

// Web
return $this->attachmentFailureRedirect($e, 'carga de documentos', 'documents');
```

Distingue el fallo atribuible al usuario (formato, tamaño) del fallo de infraestructura, que no
debe presentarse como culpa suya.

Y en tu FormRequest, `DerivesAttachmentRules` deriva las reglas de la config, para que no queden
duplicadas ni se desincronicen:

```php
use DerivesAttachmentRules;

public function rules(): array
{
    return [
        'documents'   => ['required', 'array', 'min:1'],
        'documents.*' => $this->attachmentFileRules(),   // todos los formatos de la aplicación
    ];
}
```

### Los mensajes los da el trait, no los escribas a mano

```php
public function messages(): array
{
    return [
        ...$this->attachmentFileMessages('documents.*'),
        'documents.required' => 'Debe adjuntar al menos un documento',
    ];
}
```

> **Nunca escribas a mano un mensaje para la regla de formato o de tamaño.** Laravel asocia los
> mensajes personalizados por el **nombre de la regla**, que es un detalle interno de
> `attachmentFileRules()`. Si ese nombre cambia, tu mensaje deja de dispararse **sin producir
> ningún error** —la clave sobrante se ignora en silencio— y el usuario ve la clave de traducción
> en crudo, tipo `validation.extensions`. Ya pasó una vez, al cambiar la regla de `mimes` a
> `extensions`.

Lo que pongas **después** del *spread* (`...`) gana, así que puedes sobrescribir cualquiera de los
tres textos sin perder los otros.

### Restringir los formatos de tu módulo

**Sin argumentos se aceptan todos** los formatos que la aplicación permita. Es el valor por
defecto a propósito: si tu módulo no tiene una opinión sobre formatos, no tiene que expresarla, y
un formato nuevo en la configuración le llega solo.

Si tu negocio sí la tiene —un comprobante contable no necesita fotos— **estréchala con `only:`**:

```php
// rules()
'documents.*' => $this->attachmentFileRules(
    only: ['pdf', 'xlsx', 'xlsm', 'csv', 'txt'],
),

// messages() — el mismo `only:`, porque el texto enumera los formatos aceptados
...$this->attachmentFileMessages('documents.*', only: ['pdf', 'xlsx', 'xlsm', 'csv', 'txt']),
```

> **`only:` solo puede estrechar, nunca ampliar.** La lista se intersecta con lo que la
> aplicación permite, y las extensiones de `blocked_extensions` quedan fuera pase lo que pase.
> Tu módulo puede decidir que no quiere imágenes; **no** puede decidir que sí quiere `.html`.

Es la frontera entre las dos responsabilidades: **la seguridad la garantiza el paquete, la
política la decides tú.**

### Las tres categorías de formato

| Categoría | Config | Cómo se valida |
|---|---|---|
| **Verificados** | `allowed_types` | Se leen los primeros bytes y deben coincidir con la firma |
| **Sin firma** | `opaque_types` | Se aceptan por extensión: `txt`, `log`, `csv`… no tienen firma que contrastar |
| **Bloqueados** | `blocked_extensions` | Se rechazan siempre, antes de leer nada |

Un cuarto caso son las **declaraciones equivalentes** (`equivalent_declarations`): un `.xlsm` es
un OOXML idéntico a un `.xlsx` por dentro, así que se verifica como tal pero **se guarda con la
extensión declarada**, sin perder el subtipo.

> **La validación del FormRequest es la primera barrera, no la única.** Solo cubre lo que entra
> por ahí; el módulo vuelve a validar el contenido en `store()`. Si un camino no pasa por un
> FormRequest —una cola, un import—, la garantía la sigue dando el módulo.

---

## 7. Checklist para un módulo consumidor nuevo

1. **Crear la pivote.** Mínimo: `attachment_id`, el id de tu entidad, `created_by`,
   `updated_by`, `deleted_at`. Añade tus columnas de negocio (`is_private`, categoría, etc.).
2. **Elegir los slugs del dueño.** `module` y `entity` en minúsculas, sin puntos ni barras — se
   validan porque van dentro de una ruta. Decide si necesitas **ámbito**: úsalo si distintas
   familias de archivos deben ir a destinos distintos o quedar separadas en la ruta.
3. **Servicio de escritura** con el patrón de la sección 3: valida → `store()` fuera →
   `register()` + pivote dentro → compensa → cierra.
4. **Servicio de lectura** que compruebe la pertenencia en tu pivote (sección 4).
5. **Servicio de borrado** con baja lógica en ambas tablas (sección 5).
6. **Controlador** con `UploadedFileAttachmentFactory` y `TranslatesAttachmentErrors`.
7. **FormRequest** con `DerivesAttachmentRules`, usando `only:` si tu negocio restringe formatos.
8. **Si necesitas un destino propio**, decláralo en `landlord.tenant_disks` con el `purpose` que
   produce tu `AttachmentOwner::purpose()` (`"studies"` o `"studies.<ámbito>"`). No toques
   `config/attachments.php` para eso.

---

## 8. Antipatrones

| ❌ No hagas | ✅ Haz |
|---|---|
| Guardar la ruta, el disco o el tamaño en tu pivote | Guardar solo `attachment_id` y consultar con `find()` |
| Persistir la URL del archivo | Generarla al momento con `url()` |
| `join` contra la tabla de adjuntos | `AttachmentRegistryPort::find()` |
| Subir dentro de la transacción | `store()` fuera, `register()` dentro |
| Olvidar la compensación en el `catch` | `use DiscardsStoredAttachments` |
| `delete()` en el borrado de negocio | Baja lógica con `forget()` + tu `unlink()` |
| `find()` y servir el archivo sin más | Comprobar antes la pertenencia en tu pivote |
| Inyectar `StoreAttachmentService` | Inyectar `AttachmentStoragePort` |
| Recibir `UploadedFile` en el servicio | Convertir a `AttachmentBinary` en el controlador |
| Reimplementar la validación de tipos | `DerivesAttachmentRules` |
| Escribir a mano `'documents.*.extensions' => …` | `...$this->attachmentFileMessages('documents.*')` |

---

## 9. Límite vigente: privacidad

`store()` **no recibe la visibilidad**: todo se escribe en el mismo disco. Si tu pivote tiene un
`is_private`, hoy solo sirve para omitir el enlace al leer — el objeto sigue en el disco por
defecto, que puede ser público.

Para documentos que deban ser privados **de verdad**, sirve siempre por `readStream()` detrás de
tu comprobación de permisos, nunca por `url()`. Es la única protección real disponible mientras
el módulo no gestione visibilidad. El plan de salida está en `config/attachments.php`, nota
«Documentos privados».

---

## 10. Referencias

- Contratos: `src/Contracts/` — los PHPDoc explican el porqué de cada decisión.
- Guía extensa con arquitectura, modelo de datos y operación:
  `documentation/documentation-modulo-adjuntos.html`.
- Variables de entorno: `documentation/variables-entorno.md`.
- Qué rompe y qué no al actualizar: `CHANGELOG.md`.

---

## 11. Archivo de sistema: otro contrato, otras reglas

`ArchiveStoragePort` **no es para adjuntos**. Es para archivos que **genera la propia
aplicación** y se guardan con un nombre que ella decide: hoy, las copias de seguridad de base de
datos que Suite envía al Synology. No lo uses para lo que sube un usuario, y no uses
`AttachmentStoragePort` para una copia de seguridad.

| | `AttachmentStoragePort` | `ArchiveStoragePort` |
|---|---|---|
| Origen del contenido | Terceros | La propia aplicación |
| Nombre físico | ULID | La clave que decide quien llama |
| Destino | Por tenant y propósito (`tenant_disks`) | Un disco fijo: `ATTACHMENTS_ARCHIVE_DISK` |
| Sin destino configurado | Cae al disco por defecto | Falla |
| Registro en base de datos | Sí, con `AttachmentRegistryPort` | Ninguno |
| Tamaño | Máximo `max_size_bytes` | Sin tope, por streaming |
| Borrado | `delete()` | No existe: lo archivado es permanente |

```php
public function __construct(
    private readonly ArchiveStoragePort $archive,
) {}

// $localPath ya existe; el archivo local sigue siendo de quien llama.
$archived = $this->archive->store($localPath, 'backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump');

Log::channel('database_backups')->info('archivado', [
    'clave' => $archived->key,
    'destino' => $archived->destination,
    'bytes' => $archived->sizeBytes,
    'sha256' => $archived->sha256,
    'intentos' => $archived->attempts,
    'ya_estaba' => $archived->alreadyArchived,
]);
```

Errores, con el mismo `isCallerFault()` del resto del módulo:

| Excepción | Significa | ¿Reintentar? |
|---|---|---|
| `ArchiveRejectedException` | Clave, tipo, firma o archivo local no válidos, o la clave ya existe con otro contenido | No: corrige el envío |
| `ArchiveDestinationException` | Destino sin configurar, no declarado, público o que rechaza las credenciales | No: corrige el despliegue. Si recorres varios archivos, **detén la corrida**: seguir con credenciales malas bloquea la IP en el NAS |
| `ArchiveStorageFailedException` | El transporte falló en todos los intentos | Sí, más tarde: el servicio ya reintentó |

**No persistas `ArchivedFile`.** Todo lo necesario para encontrar el archivo ya está en su clave,
que es la misma en el origen y en el destino. Va al log, no a una tabla.
