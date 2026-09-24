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

- **Archivo de sistema: `ArchiveStoragePort`.** Tercer contrato público, independiente de los
  adjuntos. Guarda en un destino externo —el Synology por SFTP, un bucket— un archivo que
  **genera la propia aplicación** (copias de seguridad de base de datos), con la clave exacta que
  decide quien llama:

  ```php
  $archived = $archive->store(
      '/var/www/backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump',
      'backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump',
  );
  ```

  Lo que lo separa de `AttachmentStoragePort`, y por qué no se reutilizó:

  - **La clave se respeta**, no se sustituye por un ULID: en una copia de seguridad el nombre es
    la información. Se valida segmento a segmento para que ningún valor salga de la raíz del
    destino (`..`, rutas absolutas, `\`, archivos ocultos).
  - **Sin tenant, sin `tenant_disks`, sin registro en base de datos.** El landlord también se
    puede archivar.
  - **Sin disco de reserva.** Si `ATTACHMENTS_ARCHIVE_DISK` falta o apunta a un disco no
    declarado, falla. Un disco público —`public` por nombre, `visibility => public` o raíz local
    dentro de lo que sirve el servidor web— también se rechaza.
  - **Sin tope de tamaño y por streaming.** Probado con 96 MB: la memoria no crece con el archivo.
  - **Subida atómica.** Cada intento escribe `<clave>.<aleatorio>.part`, compara el tamaño remoto
    con el local y solo entonces renombra. Nunca aparece con su nombre final un archivo a medias.
  - **No sobrescribe.** La misma subida repetida es inocua (`alreadyArchived`); otro contenido
    con la misma clave se rechaza.
  - **Tipos en lista cerrada** (`archive.types`), con la firma que debe tener el archivo: `dump`
    exige `PGDMP`, la cabecera de `pg_dump --format=custom`. Las `blocked_extensions` ganan
    siempre.
  - **Reintenta solo el transporte** (`archive.attempts`, `archive.retry_delay_seconds`). Un
    envío rechazado no se repite, y unas **credenciales rechazadas tampoco**: el adaptador las
    distingue y corta al primer intento, porque reintentarlas acerca el bloqueo automático de la
    IP en el Synology.
  - **No tiene `delete()`**: lo archivado es permanente.

  Devuelve `ArchivedFile` (clave, destino, tamaño, sha256, intentos) para el log de quien llama.

- 48 pruebas nuevas: la clave, el recorrido real sobre un disco local, un volcado de 96 MB con
  medición de memoria, los destinos que se rechazan y un destino en memoria que no contesta,
  rechaza las credenciales, se corta, pierde bytes, no deja renombrar o pierde la respuesta de un
  renombrado que sí ocurrió. Total: **166**.

- **Verificado además contra un servidor SFTP real** (`atmoz/sftp` haciendo de Synology, con
  `league/flysystem-sftp-v3`) y dumps reales de `pg_dump`: 52,7 MB en menos de un segundo con
  6,5 MB de memoria, sha256 idéntico en origen y destino, sin `.part` residuales, archivos `0600`
  y carpetas `0700` con `visibility => private`. Una contraseña equivocada corta al primer
  intento con `ArchiveDestinationException`; un NAS apagado agota los tres intentos, y el mensaje
  lleva la causa real, no solo el envoltorio de Flysystem.

### Acción al actualizar

- **Ninguna obligatoria.** `archive` es una clave de **primer nivel** de `config/attachments.php`,
  así que llega sola aunque la aplicación haya publicado su config. Los adjuntos no cambian.
- **Para usar el archivo de sistema:** declarar el disco en `config/filesystems.php`, apuntar
  `ATTACHMENTS_ARCHIVE_DISK` a él y, si es SFTP, instalar `league/flysystem-sftp-v3` en la
  aplicación. Ver `documentation/variables-entorno.md`, sección 4.

## [1.2.0] - 2026-09-23

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

- **`DerivesAttachmentRules::attachmentFileMessages()`**, que entrega los textos en español ya
  asociados a las reglas que emite `attachmentFileRules()`:

  ```php
  public function messages(): array
  {
      return [
          ...$this->attachmentFileMessages(),
          'attachments.required' => 'Debe adjuntar al menos un documento',
      ];
  }
  ```

  Acepta el nombre del campo y el mismo `only:` que las reglas, porque el texto enumera los
  formatos aceptados. Lo que se sobrescriba después del *spread* gana.

  **Por qué se añade:** un mensaje personalizado de Laravel se asocia por el nombre de la regla
  (`attachments.*.extensions`), así que escribirlo en el FormRequest obligaba al consumidor a
  conocer un detalle interno de este trait. Al cambiar la regla en esta misma versión, los
  mensajes de FINTEGRA dejaron de dispararse **sin ningún error** —una clave de mensaje que sobra
  no falla, simplemente no se usa— y el usuario pasó a ver `validation.extensions` en crudo.
  Derivándolos aquí, el nombre de la regla vive en un solo sitio.

- 23 pruebas nuevas: 11 para las tres categorías de formato y 12 para el trait, que **no tenía
  ninguna**. Entre ellas la que habría atrapado el fallo de los mensajes: comprueba que la clave
  de cada mensaje corresponda a una regla realmente emitida. Total: **118**.

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

  🔴 **Acción al actualizar, obligatoria en todo FormRequest con mensaje propio de formato.** Un
  mensaje personalizado se asocia por el nombre de la regla, así que `'attachments.*.mimes'` dejó
  de dispararse. No produce ningún error: la clave sobrante se ignora y el usuario ve la clave de
  traducción en crudo (`validation.extensions`). Sustituye el mensaje escrito a mano por el que
  ahora entrega el trait:

  ```diff
  - 'attachments.*.max'   => 'Cada archivo no puede superar '.$this->attachmentMaxMegabytes().' MB',
  - 'attachments.*.mimes' => 'Formato no permitido ('.implode(', ', $this->allowedExtensions()).')',
  + ...$this->attachmentFileMessages(),
  ```

  Buscar `attachments.*.mimes` en la aplicación es suficiente para encontrarlos todos. En FINTEGRA
  eran tres: `StoreStudyDocumentRequest`, `StoreFollowUpRequest` y `StorePolicyVersionRequest`.

- **Acción al actualizar por las tres claves de configuración nuevas: ninguna.**
  `equivalent_declarations`, `opaque_types` y `blocked_extensions` son claves de **primer nivel**,
  y esas sí las aporta el paquete a través de `mergeConfigFrom` aunque la aplicación tenga su
  `config/attachments.php` publicado. Copiarlas a la config de la aplicación es opcional y solo
  sirve para dejarlas a la vista y poder ajustarlas.

  `allowed_types` **no se renombró** a propósito: ahí el merge superficial sí muerde, porque la
  aplicación que publicó su config gana con el array entero y no habría visto la clave nueva.

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
