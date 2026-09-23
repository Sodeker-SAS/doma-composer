<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Archivos y carpetas de verdad para las pruebas del archivo de sistema.
 *
 * SON ARCHIVOS REALES EN DISCO Y NO CADENAS porque lo que se prueba es justamente el camino por
 * streaming: un contenido en memoria no demostraría que un volcado de cientos de megas no se
 * carga entero.
 */
final class ArchiveFixtures
{
    /** Cabecera del formato personalizado de `pg_dump`. */
    public const PG_DUMP_SIGNATURE = 'PGDMP';

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/archivo-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    public static function file(string $dir, string $name, string $content): string
    {
        $path = $dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public static function dump(string $dir, string $name = 'origen.dump', string $body = ' contenido del volcado'): string
    {
        return self::file($dir, $name, self::PG_DUMP_SIGNATURE.$body);
    }

    /**
     * Un volcado del tamaño pedido. Tras la firma va relleno de ceros: el contenido da igual, lo
     * que importa es que hay que leerlo y escribirlo entero.
     */
    public static function largeDump(string $dir, int $bytes): string
    {
        $path = $dir.'/grande.dump';
        $handle = fopen($path, 'wb');
        fwrite($handle, self::PG_DUMP_SIGNATURE);
        ftruncate($handle, $bytes);
        fclose($handle);

        return $path;
    }

    /**
     * Rutas relativas de todos los archivos bajo `$dir`, ordenadas.
     *
     * @return list<string>
     */
    public static function filesIn(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }

        sort($files);

        return $files;
    }

    public static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
