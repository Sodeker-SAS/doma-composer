# sodeker/laravel-attachments

Paquete Composer que concentra el módulo compartido de adjuntos de las aplicaciones Laravel 12 de Sódeker. Valida el contenido real de cada archivo, resuelve el destino por tenant y propósito, escribe mediante Flysystem y registra sus metadatos.

## Instalación

Declara este repositorio como VCS o como repositorio Composer de tipo `path` durante desarrollo local y luego instala el paquete:

```bash
composer require sodeker/laravel-attachments
php artisan vendor:publish --tag=attachments-config
```

El paquete **no publica migraciones**. Cada aplicación crea su tabla de adjuntos con sus propias
migraciones, usando el prefijo de su esquema. La estructura esperada está documentada, sin ser
ejecutable, en [`database/schema/`](database/schema/).

Laravel descubre `Sodeker\Attachments\AttachmentsServiceProvider` automáticamente.

## Configuración por aplicación

Cada aplicación debe declarar al menos su prefijo y el nombre de su tabla existente:

```dotenv
ATTACHMENTS_APP_PREFIX=app
ATTACHMENTS_TABLE=attachments
ATTACHMENTS_CONNECTION=tenant
ATTACHMENTS_DISK=public
```

Estas variables son identidad persistente de la integración y se eligen una sola vez, al instalar.

`ATTACHMENTS_TABLE` y `ATTACHMENTS_CONNECTION` son las críticas: si apuntan a una tabla o una conexión distintas de las reales, los registros existentes no están y los adjuntos desaparecen de la aplicación aunque los archivos sigan en disco.

`ATTACHMENTS_APP_PREFIX` es menos grave de lo que parece: la clave de cada adjunto se persiste en su fila y la lectura usa esa clave, no un recálculo, así que cambiarlo **no deja inaccesible lo ya escrito** — solo parte el almacenamiento en dos raíces y desordena lo que venga después.

`tenant_disks` vive en la conexión `landlord` y el paquete **solo la lee**: no hay modelo, ni escritura, ni comando para administrarla. Crear y mantener sus filas es responsabilidad de la aplicación o de operación. Su estructura también está en [`database/schema/`](database/schema/).

## Tenancy y almacenamiento

El provider ofrece implementaciones predeterminadas para `ResolvesStorageTenantInterface` y `ResolvesTenantDiskInterface` con `bindIf` y `scopedIf`. Para otro modelo de tenancy, registra implementaciones propias antes de que se resuelva cualquiera de esos contratos.

Los destinos S3 requieren `league/flysystem-aws-s3-v3`; los destinos SFTP o NAS requieren `league/flysystem-sftp-v3`.

## Contratos públicos

- `Sodeker\Attachments\Contracts\AttachmentStoragePort`: guarda, abre, elimina y genera URLs para el contenido físico.
- `Sodeker\Attachments\Contracts\AttachmentRegistryPort`: registra, consulta y da de baja lógica la ficha del adjunto.

La arquitectura, el modelo de datos, los ejemplos de consumo y la operación de S3/Synology están en la [guía extensa](documentation/documentation-modulo-adjuntos.html).

## Documentación del paquete

| Archivo | Para qué |
|---|---|
| [`documentation/documentation-instalacion-paquete.html`](documentation/documentation-instalacion-paquete.html) | **Empieza aquí para instalar.** Proceso completo de claves SSH, acceso al repositorio privado, los 7 pasos de instalación y un árbol de diagnóstico cuando falla. |
| [`documentation/documentation-pruebas-locales.html`](documentation/documentation-pruebas-locales.html) | Cómo modificar el paquete y probarlo dentro de una aplicación real **sin publicar versiones**: path repository, symlink, montaje en Docker y vuelta a producción. Ejemplo con FINTEGRA. |
| [`AGENTS.md`](AGENTS.md) | Cómo consumir el módulo desde un módulo de negocio: contratos, los tres flujos con el ejemplo de Estudios, checklist y antipatrones. Escrito para que lo siga un agente de IA. |
| [`documentation/variables-entorno.md`](documentation/variables-entorno.md) | Las cuatro variables del paquete, qué hace cada una y qué infraestructura espera de la aplicación. Incluye el patrón de varios servidores SFTP. |
| [`CHANGELOG.md`](CHANGELOG.md) | Qué cambió en cada versión y **qué acción manual exige actualizar**. Léelo antes de cada `composer update`. |
| [`documentation/`](documentation/documentation-modulo-adjuntos.html) | Guía extensa: arquitectura, modelo de datos y operación. |

## Desarrollo

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
```
