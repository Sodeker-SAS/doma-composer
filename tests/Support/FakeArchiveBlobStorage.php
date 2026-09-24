<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests\Support;

use RuntimeException;
use Sodeker\Attachments\Domain\Exceptions\ArchiveDestinationException;
use Sodeker\Attachments\Domain\Repositories\ArchiveBlobStorageInterface;

/**
 * Destino de archivo en memoria que falla a voluntad.
 *
 * Existe para probar lo que un disco local nunca hace por sí solo: cortarse a mitad de la
 * escritura, aceptar menos bytes de los enviados o negarse a renombrar. Es el comportamiento que
 * hay que esperar de un NAS al otro lado de la red.
 */
final class FakeArchiveBlobStorage implements ArchiveBlobStorageInterface
{
    /** @var array<string, string> clave => contenido */
    public array $files = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $writtenKeys = [];

    /** Cuántas escrituras fallan, a mitad del contenido, antes de empezar a funcionar. */
    public int $failWrites = 0;

    /** Bytes que el destino «pierde» en cada escritura sin reportar error. */
    public int $dropBytes = 0;

    public bool $failMove = false;

    /** Cuántas consultas de existencia fallan (NAS que no contesta) antes de responder. */
    public int $failExists = 0;

    /** El renombrado se completa pero la respuesta se pierde: el caso más traicionero. */
    public bool $loseMoveResponse = false;

    /** El destino rechaza usuario o contraseña, como lo traduce el adaptador real. */
    public bool $rejectCredentials = false;

    public int $existsCalls = 0;

    public function __construct(
        private readonly string $disk = 'nas_pruebas',
    ) {}

    public function destination(): string
    {
        return $this->disk;
    }

    public function exists(string $key): bool
    {
        if ($this->rejectCredentials) {
            $this->existsCalls++;

            throw ArchiveDestinationException::authenticationFailed(
                $this->disk,
                new RuntimeException('Unable to authenticate using a password.'),
            );
        }

        if (++$this->existsCalls <= $this->failExists) {
            throw new RuntimeException('Unable to connect to host: tiempo de espera agotado.');
        }

        return isset($this->files[$key]);
    }

    public function size(string $key): int
    {
        if (! isset($this->files[$key])) {
            throw new RuntimeException("«{$key}» no existe en el destino.");
        }

        return strlen($this->files[$key]);
    }

    public function write(string $key, $stream): void
    {
        $this->writtenKeys[] = $key;
        $content = (string) stream_get_contents($stream);

        if (count($this->writtenKeys) <= $this->failWrites) {
            // Como un corte de red real: el temporal queda a medias en el destino.
            $this->files[$key] = substr($content, 0, intdiv(strlen($content), 2));

            throw new RuntimeException('Conexión reiniciada por el servidor.');
        }

        $this->files[$key] = $this->dropBytes > 0 ? substr($content, 0, -$this->dropBytes) : $content;
    }

    public function move(string $from, string $to): void
    {
        if ($this->failMove) {
            throw new RuntimeException('Permiso denegado al renombrar.');
        }

        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);

        if ($this->loseMoveResponse) {
            $this->loseMoveResponse = false;

            throw new RuntimeException('Conexión cerrada antes de confirmar el renombrado.');
        }
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        unset($this->files[$key]);
    }
}
