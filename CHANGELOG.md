# Changelog

Todos los cambios relevantes de `sodeker/laravel-attachments`.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado es
[SemVer](https://semver.org/lang/es/).

## Cómo leer este archivo antes de actualizar

Cada versión indica si requiere **acción manual** en la aplicación consumidora. Un
`composer update` NO basta cuando la entrada lo dice, por dos motivos recurrentes:

- **Config publicada.** El provider usa `mergeConfigFrom`, que fusiona **solo el primer nivel**.
  Si la aplicación publicó `config/attachments.php`, su copia gana entera sobre cada clave de
  primer nivel. Un valor nuevo dentro de `allowed_types`, `container_markers`,
  `extension_aliases` o `signature_search_bytes` **no llega solo**: hay que copiarlo a mano a la
  config publicada. Las claves de primer nivel nuevas sí llegan solas.
- **Esquema.** El paquete no publica migraciones: cada aplicación crea y versiona sus propias
  tablas. Cuando una versión cambie el esquema esperado, la entrada lo indicará y **cada
  aplicación debe escribir su migración**. La estructura de referencia vive en
  `database/schema/`, que no es ejecutable.

## Qué se considera ruptura en este paquete

Superficie pública, cuyo cambio incompatible obliga a versión MAYOR:

- Los contratos `AttachmentStoragePort` y `AttachmentRegistryPort`.
- Los value objects que consume la aplicación: `AttachmentBinary`, `AttachmentOwner`,
  `StoredAttachment`, `AttachmentLocation`.
- La entidad `Attachment` y la jerarquía de `AttachmentException`.
- Los traits de `Concerns/`, porque los módulos consumidores los montan con `use`.
- `UploadedFileAttachmentFactory`, frontera HTTP de la aplicación.
- Las claves de `config/attachments.php` y el esquema de la tabla de adjuntos.
- **El formato de la clave física de almacenamiento.** No rompe compilación: rompe archivos ya
  escritos en producción, cuya clave está persistida. Es el cambio más delicado del paquete.
- `ResolvesTenantDiskInterface` y `ResolvesStorageTenantInterface`, porque el provider las
  registra con `bindIf`/`scopedIf` y la aplicación puede implementarlas.

Todo lo demás —adaptadores de `Infrastructure/`, repositorios, detección interna de firmas— es
interno y puede cambiar en una versión PARCHE.

---

## [Sin publicar]

### Añadido

- **Tipos sin firma binaria (`opaque_types`).** `txt`, `log`, `md`, `csv`, `json`, `xml`, `dump`,
  `sql`, `yml` y `yaml` se aceptan por su extensión, porque son bytes arbitrarios y no existe una
  firma contra la que contrastarlos. Solo se consultan cuando **ninguna** firma encajó, así que un
  archivo que sí es un formato conocido nunca llega por esta vía: un PDF renombrado a `.txt` se
  sigue detectando y rechazando.

  **Límite aceptado conscientemente:** para estos tipos no se puede detectar un archivo
  renombrado. La defensa real no es esta lista, sino el camino de lectura.

- **Extensiones bloqueadas (`blocked_extensions`).** `svg`, `html`, `htm`, `xhtml`, `shtml`,
  `php`, `phtml`, `phar`, `js`, `mjs`, `jsp`, `asp` y `aspx` se rechazan **siempre**, antes de leer
  un solo byte. No es política de negocio: son peligrosas al servirse, porque si el navegador las
  interpreta desde el dominio de la aplicación dejan de ser un dato y pasan a ser código con la
  sesión de quien las abre. **Ningún módulo consumidor puede autorizarlas.**

- **Declaraciones equivalentes (`equivalent_declarations`) y soporte de `xlsm`.** Un Excel con
  macros es OOXML igual que un `xlsx`: misma firma y mismo marcador `xl/`. Lo que los separa,
  `xl/vbaProject.bin`, está demasiado adentro del archivo para verlo en los primeros bytes. Esta
  clave permite declarar `xlsm` frente a un `xlsx` detectado **conservando la extensión
  declarada**, con lo que se mantiene la verificación de que es un OOXML válido sin perder el
  subtipo. Se declaran también `docm` y `pptm`.

  No confundir con `extension_aliases`, que **normaliza** (un `jpeg` se guarda como `jpg`).

- 11 pruebas nuevas para las tres categorías. Total: **106**.

### Cambiado

- **`DerivesAttachmentRules::attachmentFileRules()` acepta `only:`**, para que cada módulo
  consumidor estreche los formatos según su negocio:

  ```php
  'attachments.*' => $this->attachmentFileRules(only: ['pdf', 'xlsx', 'xlsm', 'csv']),
  ```

  **Sin `only:` se aceptan todos** los formatos que la aplicación permita: un consumidor sin
  opinión sobre formatos no tiene que expresarla, y un formato nuevo en la configuración le llega
  solo. `only:` **solo puede estrechar**: la intersección se calcula contra lo que la aplicación
  permite, y las extensiones bloqueadas quedan fuera pase lo que pase. La llamada sin argumentos
  se comporta igual que antes.

- **La regla de validación pasa de `mimes:` a `extensions:`.** `mimes` valida el MIME adivinado del
  contenido, y los tipos sin firma —un `.log`, un `.dump`— no tienen un MIME estable que reconozca.
  El contenido lo sigue verificando el módulo con los magic bytes, que es una comprobación más
  fuerte. Efecto práctico: un archivo renombrado ahora lo rechaza el módulo con un mensaje preciso,
  en lugar del validador con uno genérico.

- **Acción al actualizar:** las tres claves nuevas **no llegan solas** a las aplicaciones que
  publicaron su configuración —FINTEGRA y SUITE la tienen—, por el merge superficial descrito
  arriba. Hay que copiar a mano `equivalent_declarations`, `opaque_types` y `blocked_extensions`, o
  volver a publicar la config. Las aplicaciones que solo usan variables de entorno, como SAT, las
  reciben automáticamente. `allowed_types` **no se renombró** a propósito, para no romper esas
  configuraciones publicadas en silencio.

- **El paquete ya no publica migraciones.** Se retira `publishesMigrations()` y con él el tag
  `attachments-migrations`. Los dos stubs pasan de `database/migrations/` a `database/schema/`
  como **referencia de estructura no ejecutable**.

  Motivo: las aplicaciones consumidoras ya tienen su tabla de adjuntos creada por sus propias
  migraciones y con el prefijo de su esquema (`fin_attachments`, `sat_attachments`), y
  `tenant_disks` vive en la base del landlord, que es infraestructura compartida del despliegue
  y no propiedad de este módulo. En ambos casos, publicar una migración desde el paquete
  chocaría con una tabla existente. FINTEGRA, de hecho, tiene `tenant_disks` creada fuera de su
  repositorio y sin migración que la respalde.

  **Acción al actualizar:** ninguna, salvo dejar de invocar
  `vendor:publish --tag=attachments-migrations`, que ya no existe. Ninguna tabla se toca.

  **Al integrar el paquete en una aplicación nueva:** copia el contenido de
  `database/schema/attachments-table.php.stub` a una migración propia y ajusta el nombre de la
  tabla. Lo que el paquete exige son las columnas y sus tipos; el nombre y la conexión los
  declaras con `ATTACHMENTS_TABLE` y `ATTACHMENTS_CONNECTION`.

### Documentado

- `AGENTS.md`: guía para consumir el módulo desde un módulo de negocio, con el ejemplo de
  Estudios, checklist de integración y antipatrones.
- `documentation/variables-entorno.md`: las cuatro variables del paquete y la infraestructura que
  espera encontrar.
  Incluye el patrón de **varios servidores SFTP/Synology**: un disco declarado y un juego de
  variables prefijadas por servidor, con la fila de `tenant_disks` limitándose a nombrarlo.
  La primera versión del archivo sugería un juego único de `SFTP_*`, que daba a entender —mal—
  que solo cabía un servidor.
- Este `CHANGELOG.md`.
- Guía extensa: nueva sección **«Varios Synology a la vez»**, que cubre el caso de N empresas con
  N servidores propios simultáneos. La sección existente hablaba solo de *reemplazar* un destino,
  así que se renombró a «Cambiar de carpeta o mover un cliente de servidor» para separar los dos
  escenarios. Se aclara además que el prefijo `NAS_SFTP_` pertenece al disco `nas` y no al módulo.
- Corregida en el README la advertencia sobre `ATTACHMENTS_APP_PREFIX`: cambiarlo **no** deja
  inaccesibles los adjuntos históricos, porque la clave se persiste en cada fila y la lectura la
  usa tal cual. Sí parte el almacenamiento en dos raíces. La advertencia sigue siendo literal
  para `ATTACHMENTS_TABLE` y `ATTACHMENTS_CONNECTION`.

## [1.0.0] - 2026-09-02

Primera versión publicable. Extrae a paquete Composer reutilizable el módulo de adjuntos que
vivía duplicado en cada aplicación, sin cambiar su comportamiento.

### Añadido

- Contratos públicos `AttachmentStoragePort` (escribe, lee, elimina y genera URL del contenido
  físico) y `AttachmentRegistryPort` (registra, consulta y da de baja lógica la ficha).
  Están separados a propósito: la escritura del objeto ocurre fuera de la transacción del
  consumidor y el registro dentro.
- Validación del contenido real por *magic bytes*, sin confiar en la extensión ni en el
  `Content-Type` declarados. Desambiguación de la familia OOXML (`docx`, `xlsx`, `pptx`) por
  marcador de contenedor, y tolerancia a la cabecera desplazada en PDF.
- Resolución de destino por tenant y propósito contra `landlord.tenant_disks`, con reserva al
  disco por defecto cuando el cliente no declara ninguno.
- Escritura mediante Flysystem con nombre físico ULID, y registro de metadatos en la tabla
  genérica de adjuntos.
- Traits de apoyo para el consumidor: `DerivesAttachmentRules` (reglas de validación derivadas
  de la config), `DiscardsStoredAttachments` (compensación al fallar) y
  `TranslatesAttachmentErrors` (traducción de excepciones a respuesta HTTP).
- `UploadedFileAttachmentFactory` para traducir un `UploadedFile` a `AttachmentBinary` en la
  frontera HTTP.
- Config publicable (`--tag=attachments-config`) y dos stubs de migración
  (`--tag=attachments-migrations`): la tabla de adjuntos y `tenant_disks`.
  *(Los stubs se retiran como publicables en la versión siguiente; ver «Sin publicar».)*
- Auto-discovery del `AttachmentsServiceProvider`.
- 95 pruebas con Pest.

### Configurable por aplicación

Frente a la versión que vivía dentro de cada proyecto, dejan de estar fijos en el código:

- El nombre de la tabla, ahora `ATTACHMENTS_TABLE` (antes `fin_attachments` fijo).
- La conexión, ahora `ATTACHMENTS_CONNECTION` (antes `tenant` fijo).
- El prefijo de aplicación, ahora `ATTACHMENTS_APP_PREFIX` (antes `fintegra` por defecto).

### Notas de migración desde el módulo embebido

Para una aplicación que ya tenía el módulo en `app/Modules/Attachments`:

1. Reescribir los `use`: `App\Modules\Attachments\…` y `App\Shared\Attachments\…` pasan a
   `Sodeker\Attachments\…`. Los `Concerns` y `Contracts`, que estaban bajo `App\Shared`, quedan
   en `Sodeker\Attachments\Concerns` y `Sodeker\Attachments\Contracts`. Las firmas no cambian.
2. Quitar el provider del módulo de `config/app.php`: lo descubre Composer.
3. **Declarar las variables de entorno.** Los valores por defecto del paquete son genéricos, así
   que una aplicación que no las declare apuntará a una tabla que no existe.
   Ver `documentation/variables-entorno.md`.
4. Borrar `app/Modules/Attachments` y `app/Shared/Attachments`.

No hay migración de datos: la tabla, las claves físicas y los archivos existentes no se tocan.
La lectura usa la ubicación y la clave persistidas en cada fila, no un recálculo.

### Limitación conocida

El módulo no distingue adjuntos públicos de privados: `store()` no recibe la visibilidad y todo
se escribe en el disco resuelto para el cliente. Con el disco por defecto `public`, un documento
marcado como privado por el consumidor sigue siendo alcanzable por quien tenga la URL; lo único
que lo protege es que el nombre físico es un ULID. El plan de salida está documentado en la nota
«Documentos privados» de `config/attachments.php`.

[Sin publicar]: https://github.com/Fsjaimes/composer-adjuntos/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Fsjaimes/composer-adjuntos/releases/tag/v1.0.0
