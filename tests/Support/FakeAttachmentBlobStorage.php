<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use Sodeker\Attachments\Domain\Exceptions\AttachmentStorageFailedException;
use Sodeker\Attachments\Domain\Repositories\AttachmentBlobStorageInterface;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentBinary;
use Sodeker\Attachments\Domain\ValueObjects\AttachmentLocation;

/**
 * Almacenamiento en memoria para probar el caso de uso sin tocar disco.
 *
 * NO CIERRA EL FLUJO A PROPÓSITO. El adaptador real lo hace —Flysystem cierra el stream que le
 * entregan—, y si el doble lo imitara, las pruebas que comprueban que es el CASO DE USO quien
 * suelta el descriptor pasarían aunque el caso de uso no hiciera nada.
 *
 * GUARDA INDEXANDO POR UBICACIÓN, no solo por clave: lo que hay que poder afirmar en las pruebas
 * del reparto por categoría no es solo que se escribió, sino DÓNDE.
 */
final class FakeAttachmentBlobStorage implements AttachmentBlobStorageInterface
{
    /** @var array<string, array<string, string>> ubicación → clave → contenido escrito */
    public array $written = [];

    /** @var list<string> ubicaciones y claves borradas, en orden, como "ubicación|clave" */
    public array $deleted = [];

    /** Cuando es true, `put()` falla como lo haría un disco lleno o un bucket inalcanzable. */
    public bool $failsOnPut = false;

    public function put(AttachmentLocation $location, string $key, AttachmentBinary $binary): void
    {
        if ($this->failsOnPut) {
            throw AttachmentStorageFailedException::forKey($key, (string) $location);
        }

        $binary->rewind();

        $this->written[(string) $location][$key] = (string) stream_get_contents($binary->stream());
    }

    /**
     * @return resource|null
     */
    public function readStream(AttachmentLocation $location, string $key)
    {
        if (! $this->exists($location, $key)) {
            return null;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->written[(string) $location][$key]);
        rewind($stream);

        return $stream;
    }

    public function delete(AttachmentLocation $location, string $key): bool
    {
        $this->deleted[] = (string) $location.'|'.$key;

        if (! $this->exists($location, $key)) {
            return false;
        }

        unset($this->written[(string) $location][$key]);

        return true;
    }

    public function exists(AttachmentLocation $location, string $key): bool
    {
        return array_key_exists($key, $this->written[(string) $location] ?? []);
    }

    public function url(AttachmentLocation $location, string $key, int $expiresInMinutes = 5): ?string
    {
        return $this->exists($location, $key) ? "https://fake.test/{$location}/{$key}" : null;
    }

    /** Claves escritas en esa ubicación, para afirmar el reparto entre destinos. */
    public function keysAt(string $location): array
    {
        return array_keys($this->written[$location] ?? []);
    }

    /** Todas las claves escritas, sin importar dónde. */
    public function allKeys(): array
    {
        return array_merge(...array_map(array_keys(...), array_values($this->written) ?: [[]]));
    }
}
