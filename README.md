# sodeker/laravel-attachments

Paquete Composer que concentra el módulo compartido de adjuntos de las aplicaciones Laravel 12 de Sódeker. Valida el contenido real de cada archivo, resuelve el destino por tenant y propósito, escribe mediante Flysystem y registra sus metadatos.

## Instalación

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

El prefijo forma parte de las claves físicas nuevas. Cambiar `ATTACHMENTS_TABLE` cuando ya existen filas hace que el modelo deje de encontrar los registros históricos hasta migrarlos o restaurar el valor anterior.

La migración de `tenant_disks` usa la conexión `landlord`. Una aplicación que ya administra esa tabla puede publicar solo la configuración o descartar ese stub.

## Tenancy y almacenamiento

El provider ofrece implementaciones predeterminadas para `ResolvesStorageTenantInterface` y `ResolvesTenantDiskInterface` con `bindIf` y `scopedIf`. Para otro modelo de tenancy, registra implementaciones propias antes de que se resuelva cualquiera de esos contratos.

Los destinos S3 requieren `league/flysystem-aws-s3-v3`; los destinos SFTP o NAS requieren `league/flysystem-sftp-v3`.

## Desarrollo

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
```
