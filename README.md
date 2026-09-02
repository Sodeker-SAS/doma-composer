# sodeker/laravel-attachments

Paquete Composer que concentra el módulo compartido de adjuntos de las aplicaciones Laravel 12 de Sódeker. Valida el contenido real de cada archivo, resuelve el destino por tenant y propósito, escribe mediante Flysystem y registra sus metadatos.

## Instalación

Declara este repositorio como VCS o como repositorio Composer de tipo `path` durante desarrollo local y luego instala el paquete:

```bash
composer require sodeker/laravel-attachments
php artisan vendor:publish --tag=attachments-config
php artisan vendor:publish --tag=attachments-migrations
php artisan migrate
```

Laravel descubre `Sodeker\Attachments\AttachmentsServiceProvider` automáticamente.

## Configuración por aplicación

Cada aplicación debe declarar al menos su prefijo y el nombre de su tabla existente:

```dotenv
ATTACHMENTS_APP_PREFIX=app
ATTACHMENTS_TABLE=attachments
ATTACHMENTS_CONNECTION=tenant
ATTACHMENTS_DISK=public
```

Estas tres variables son identidad persistente de la integración. Cambiar `ATTACHMENTS_APP_PREFIX` o `ATTACHMENTS_TABLE` cuando ya existen datos deja inalcanzables los adjuntos históricos hasta migrar objetos y registros o restaurar los valores anteriores; cambiar `ATTACHMENTS_CONNECTION` exige mover o exponer la tabla en la nueva conexión.

La migración de `tenant_disks` usa la conexión `landlord`. Una aplicación que ya administra esa tabla puede publicar solo la configuración o descartar ese stub.

## Tenancy y almacenamiento

El provider ofrece implementaciones predeterminadas para `ResolvesStorageTenantInterface` y `ResolvesTenantDiskInterface` con `bindIf` y `scopedIf`. Para otro modelo de tenancy, registra implementaciones propias antes de que se resuelva cualquiera de esos contratos.

Los destinos S3 requieren `league/flysystem-aws-s3-v3`; los destinos SFTP o NAS requieren `league/flysystem-sftp-v3`.

## Contratos públicos

- `Sodeker\Attachments\Contracts\AttachmentStoragePort`: guarda, abre, elimina y genera URLs para el contenido físico.
- `Sodeker\Attachments\Contracts\AttachmentRegistryPort`: registra, consulta y da de baja lógica la ficha del adjunto.

La arquitectura, el modelo de datos, los ejemplos de consumo y la operación de S3/Synology están en la [guía extensa](documentation/documentation-modulo-adjuntos.html).

## Desarrollo

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
```
