# Variables de entorno

Referencia de configuración de `sodeker/laravel-attachments`.

El paquete lee **cuatro variables propias**. Todo lo demás se configura en `config/attachments.php`
—publicable con `vendor:publish --tag=attachments-config`— o en la tabla `landlord.tenant_disks`.

Las secciones 2 y 3 no son del paquete: describen lo que el paquete **espera encontrar ya montado**
en la aplicación consumidora.

---

## 1 · Variables del paquete

Copia estas cuatro al `.env` de la aplicación y ajústalas.

| Variable | Por defecto | Criticidad |
|---|---|---|
| `ATTACHMENTS_APP_PREFIX` | `app` | Cámbiala siempre |
| `ATTACHMENTS_TABLE` | `attachments` | **Crítica** |
| `ATTACHMENTS_CONNECTION` | `tenant` | **Crítica** |
| `ATTACHMENTS_DISK` | `public` | Revisa la nota de privacidad |

```dotenv
ATTACHMENTS_APP_PREFIX=
ATTACHMENTS_TABLE=
ATTACHMENTS_CONNECTION=tenant
ATTACHMENTS_DISK=public
```

### `ATTACHMENTS_APP_PREFIX`

Primer segmento de la ruta física de **todo** adjunto que escriba esta aplicación:

```
{app_prefix}/{tenant}/{modulo}/{entidad}/{id}/{ambito}/{ULID}.{ext}
fintegra/acme/studies/study/42/documents/01K7XV….pdf
```

Existe para que varias aplicaciones del ecosistema compartan almacenamiento sin pisarse.

**Cámbialo siempre.** El valor por defecto `app` es un marcador genérico: si dos aplicaciones lo
dejan igual y comparten bucket, colisionan. Ejemplos reales: `fintegra`, `sat`, `iris`.

Cambiarlo con datos ya escritos **no deja inaccesibles los archivos antiguos** —la clave se
persiste en cada fila y la lectura usa esa clave, no un recálculo—, pero sí parte el
almacenamiento en dos raíces. Se elige una vez, al instalar.

Valor: slug en minúsculas, sin puntos ni barras.

### `ATTACHMENTS_TABLE`

Tabla genérica donde el paquete registra los metadatos de cada archivo. Es configurable porque
cada aplicación prefija su esquema: `fin_attachments`, `sat_attachments`, `iris_attachments`.

> **Es la variable más peligrosa.** Si apunta a una tabla distinta de la real, los registros
> existentes sencillamente no están: los adjuntos desaparecen de la aplicación aunque los archivos
> sigan en disco. Falla de inmediato y de forma ruidosa, no en silencio.

Debe coincidir con la tabla que la aplicación creó con sus propias migraciones. La estructura
esperada está en [`database/schema/attachments-table.php.stub`](../database/schema/attachments-table.php.stub).

### `ATTACHMENTS_CONNECTION`

Conexión de `config/database.php` donde vive la tabla anterior. En una aplicación multi-tenant con
base por cliente, es la conexión del tenant.

En una aplicación de una sola base, ponla en `mysql` (o la que uses) y revisa la sección 3: las
implementaciones de tenancy por defecto no te sirven.

Igual que la tabla: cambiarla con datos existentes deja los adjuntos fuera de vista hasta
restaurar el valor o mover la tabla.

### `ATTACHMENTS_DISK`

Disco de `config/filesystems.php` que se usa cuando el cliente **no** tiene un destino declarado en
`landlord.tenant_disks` para ese propósito. Es la reserva, no el único destino posible: la
resolución por tenant tiene prioridad.

Es lo único que hay que tocar para pasar de disco local a bucket, siempre que el adaptador esté
instalado:

```bash
composer require league/flysystem-aws-s3-v3    # destinos S3
composer require league/flysystem-sftp-v3      # destinos SFTP / NAS
```

> **Aviso de privacidad.** El módulo todavía no distingue adjuntos públicos de privados: escribe
> todo en el disco resuelto. Con `public`, el objeto queda bajo `storage/app/public`, que nginx
> sirve sin pasar por Laravel, así que un documento marcado como privado por el consumidor sigue
> siendo alcanzable por quien tenga la URL. **No pongas aquí un bucket de lectura pública mientras
> eso siga así.** El detalle y el plan de salida están en `config/attachments.php`.

---

## 2 · Lo que el paquete espera del almacenamiento

El paquete **no define discos**: usa los que la aplicación declara en `config/filesystems.php`.
Cuando un destino no está declarado, lo registra al vuelo **copiando entera** la definición del
disco base, así que el driver que haya detrás —`sftp`, `ftp`, `s3` con endpoint propio— le es
indiferente.

> **Un destino = un disco declarado.** La fila de `tenant_disks` solo **nombra** el disco
> (`{"disk":"nas_acme"}`); las credenciales nunca salen de la base de datos.

### S3 o compatible

Requiere `league/flysystem-aws-s3-v3`. **Un solo juego de credenciales basta para varios
clientes**: todos las comparten y lo que cambia es el bucket, que viaja en la fila como
`{"bucket": "..."}`.

```dotenv
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_URL=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### SFTP / NAS · Synology

Requiere `league/flysystem-sftp-v3`.

> **Aquí no sirve un juego único de variables.** Cada servidor es una empresa distinta, con su
> host, su usuario y su credencial. Se declara **un disco por servidor**, cada uno con variables
> prefijadas propias.

```php
// config/filesystems.php
'nas_acme' => [
    'driver'     => 'sftp',
    'host'       => env('NAS_ACME_SFTP_HOST'),
    'port'       => (int) env('NAS_ACME_SFTP_PORT', 22),
    'username'   => env('NAS_ACME_SFTP_USERNAME'),
    'password'   => env('NAS_ACME_SFTP_PASSWORD') ?: null,
    'privateKey' => env('NAS_ACME_SFTP_PRIVATE_KEY') ?: null,
    'root'       => '/adjuntos',
    'throw'      => true,
],
```

Y cada cliente apunta al suyo en `landlord.tenant_disks`:

| Cliente | `config` |
|---|---|
| Acme | `{"disk": "nas_acme"}` |
| Beta | `{"disk": "nas_beta"}` |
| Gamma | `{"disk": "nas_gamma"}` |

Un bloque de variables por servidor:

```dotenv
NAS_ACME_SFTP_HOST=
NAS_ACME_SFTP_PORT=22
NAS_ACME_SFTP_USERNAME=
NAS_ACME_SFTP_PASSWORD=
NAS_ACME_SFTP_PRIVATE_KEY=
NAS_ACME_SFTP_PASSPHRASE=

NAS_BETA_SFTP_HOST=
NAS_BETA_SFTP_PORT=22
NAS_BETA_SFTP_USERNAME=
NAS_BETA_SFTP_PASSWORD=
NAS_BETA_SFTP_PRIVATE_KEY=
NAS_BETA_SFTP_PASSPHRASE=
```

#### Tres detalles que rompen en silencio

**El `?: null` no es adorno.** Una variable declarada pero vacía llega como cadena vacía, que no es
`null`. `SftpConnectionProvider` elige el método de autenticación con `if ($privateKey !== null)`,
así que intentaría autenticar por clave, fallaría con «Unable to load private key» y nunca probaría
la contraseña. El síntoma no delata la causa.

**`throw => true` a propósito.** Sin él, un NAS caído hace que Flysystem devuelva `false` en
silencio y el módulo no puede distinguirlo de un archivo que no existe.

**`root` es identidad, no configuración.** Equivale al nombre del bucket en S3: las claves
guardadas son *relativas* a esa raíz, y la raíz no se persiste en ninguna parte. Cambiarla deja de
abrir todos los adjuntos de ese cliente **en silencio**. Para cambiar de carpeta se declara un
disco **nuevo** y se repunta la fila; el viejo se queda declarado para siempre, porque hay filas
que lo nombran y tienen que poder leerse.

#### Límite conocido

Dar de alta un cliente con NAS propio **no es solo un `INSERT`**. Exige tocar `filesystems.php`,
añadir variables y desplegar, porque el paquete nunca lee credenciales de la base de datos. Es
deliberado. Si algún día molesta, la salida es un gestor de secretos externo referenciado desde la
fila, nunca credenciales en la tabla.

---

## 3 · Lo que el paquete espera de la aplicación

No son variables: son **requisitos de infraestructura**. Compruébalos antes de instalar, porque las
implementaciones de tenancy por defecto dependen de ellos.

1. **Conexión `landlord`** en `config/database.php`. `LandlordTenantDiskResolver` lee ahí la tabla
   `tenant_disks` para saber a qué destino va cada cliente según el propósito.

2. **Tablas `tenants` y `tenant_disks`** en esa conexión. El paquete **solo lee** `tenant_disks`;
   crearla y mantener sus filas es responsabilidad de la aplicación. Su estructura está documentada
   en [`database/schema/tenant-disks-table.php.stub`](../database/schema/tenant-disks-table.php.stub).

3. **`database.connections.tenant.database`** con el nombre real de la base del cliente durante la
   petición. De ahí sale el identificador del tenant que se usa como segundo segmento de la ruta
   física.

### Si la aplicación no es multi-tenant

No necesitas `tenant_disks` en absoluto. Registra en un provider propio tus implementaciones de:

```
Sodeker\Attachments\Domain\Repositories\ResolvesTenantDiskInterface
Sodeker\Attachments\Domain\Repositories\ResolvesStorageTenantInterface
```

El provider del paquete las enlaza con `bindIf` y `scopedIf`, así que si tú ya las registraste, el
paquete respeta las tuyas y no las sobrescribe.

---

## 4 · Archivo de sistema · `ArchiveStoragePort`

Variables del contrato de archivo de sistema, aparte de las cuatro de los adjuntos. No
intervienen en los adjuntos de usuario ni en `tenant_disks`.

| Variable | Por defecto | Criticidad |
|---|---|---|
| `ATTACHMENTS_ARCHIVE_DISK` | *(ninguno)* | **Obligatoria para archivar** |
| `ATTACHMENTS_ARCHIVE_ATTEMPTS` | `3` | Ajuste |
| `ATTACHMENTS_ARCHIVE_RETRY_DELAY` | `10` (segundos) | Ajuste |

### `ATTACHMENTS_ARCHIVE_DISK`

Nombre de un disco declarado en `config/filesystems.php`. **No tiene valor por defecto a
propósito:** si falta, `store()` falla con `ArchiveDestinationException` antes de escribir un
byte. Tampoco acepta un disco público —`public`, uno con `visibility => public` o uno local cuya
raíz esté bajo `public/` o `storage/app/public`—, porque ahí una copia de la base de datos quedaría
descargable por URL.

Para el Synology, un disco SFTP propio, separado de los NAS de clientes que usan los adjuntos:

```php
// config/filesystems.php
'synology_backups' => [
    'driver'     => 'sftp',
    'host'       => env('SYNOLOGY_BACKUPS_SFTP_HOST'),
    'port'       => (int) env('SYNOLOGY_BACKUPS_SFTP_PORT', 22),
    'username'   => env('SYNOLOGY_BACKUPS_SFTP_USERNAME'),
    'password'   => env('SYNOLOGY_BACKUPS_SFTP_PASSWORD') ?: null,
    'privateKey' => env('SYNOLOGY_BACKUPS_SFTP_PRIVATE_KEY') ?: null,
    'passphrase' => env('SYNOLOGY_BACKUPS_SFTP_PASSPHRASE') ?: null,
    'root'       => env('SYNOLOGY_BACKUPS_SFTP_ROOT', '/'),
    'timeout'    => 30,
    'visibility'           => 'private',   // archivos 0600
    'directory_visibility' => 'private',   // carpetas 0700
    'throw'      => true,
],
```

`visibility` y `directory_visibility` en `private` dejan cada dump legible solo por el usuario
SFTP de las copias, no por cualquier cuenta del NAS. Si la carpeta compartida del Synology usa
permisos ACL de Windows y rechaza el `chmod`, la escritura falla con `UnableToSetVisibility`: en
ese caso se quitan las dos líneas y el acceso lo gobiernan las ACL de la carpeta.

```dotenv
ATTACHMENTS_ARCHIVE_DISK=synology_backups
SYNOLOGY_BACKUPS_SFTP_HOST=
SYNOLOGY_BACKUPS_SFTP_PORT=22
SYNOLOGY_BACKUPS_SFTP_USERNAME=
SYNOLOGY_BACKUPS_SFTP_PASSWORD=
SYNOLOGY_BACKUPS_SFTP_PRIVATE_KEY=
SYNOLOGY_BACKUPS_SFTP_PASSPHRASE=
SYNOLOGY_BACKUPS_SFTP_ROOT=/
```

Aplican los mismos tres detalles de la sección 2: `?: null` en las credenciales, `throw => true`
y `root` como identidad. La clave que recibe `store()` es **relativa a `root`**: con
`root => '/'` y la clave `backups/prevesa/23_09_2026/prevesa_23_09_2026_01_00_03.dump`, el archivo queda
en `/backups/prevesa/…` del Synology.

Requiere `league/flysystem-sftp-v3` instalado **en la aplicación**.

### `ATTACHMENTS_ARCHIVE_ATTEMPTS` y `ATTACHMENTS_ARCHIVE_RETRY_DELAY`

Intentos de la subida completa y segundos de espera entre uno y otro. Solo se reintenta el
transporte (red cortada, NAS que no responde): un envío rechazado por clave, tipo o firma falla al
primer intento, porque repetirlo daría lo mismo.

**Las credenciales rechazadas tampoco se reintentan**, y ahí el motivo es otro: el Synology cuenta
los inicios de sesión fallidos y, con el «bloqueo automático» activo, bloquea la IP de origen
pasado un umbral. Con tres intentos por archivo y varios archivos por corrida, una contraseña mal
configurada dejaría al servidor bloqueado en segundos. Llegan como `ArchiveDestinationException`
al primer intento. Quien recorra varios archivos en una corrida debería además dejar de intentar
los siguientes ante esa excepción.

Cada intento empieza desde cero sobre un temporal nuevo (`<clave>.<aleatorio>.part`), y el
temporal fallido se borra. Si el proceso muere a mitad de la subida puede quedar un `.part`
huérfano en el destino: nunca tiene el nombre final y puede borrarse sin riesgo.

### Tipos que se archivan

No es variable: es la lista cerrada `archive.types` de `config/attachments.php`, con la firma que
debe tener cada tipo. Hoy solo `dump` (`PGDMP`, la cabecera de `pg_dump --format=custom`). Un dump
en texto plano o cortado a la mitad no la tiene y se rechaza antes de subir nada.
