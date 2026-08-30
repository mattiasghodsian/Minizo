<?php

namespace App\Services\Metadata;

use App\Exceptions\MetadataException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Metaflac
{
    private const TIMEOUT = 60;

    /** Values we pass are already UTF-8, and metaflac would otherwise transcode them from the process's locale charset - which in a container is often POSIX/C. */
    public const NO_TRANSCODE = '--no-utf8-convert';

    /** Whether the metaflac binary can be found. */
    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * @param  array<int, string>  $arguments
     * @return string stdout
     *
     * @throws MetadataException
     */
    public function run(array $arguments): string
    {
        $binary = $this->binary() ?? throw MetadataException::toolUnavailable();

        $process = new Process([$binary, ...$arguments], timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new MetadataException(__('metaflac timed out.'));
        }

        if (! $process->isSuccessful()) {
            throw new MetadataException(
                trim($process->getErrorOutput() ?: $process->getOutput())
                    ?: 'metaflac exited with '.$process->getExitCode()
            );
        }

        return $process->getOutput();
    }

    /** The path to metaflac, or null when it is not installed. */
    private function binary(): ?string
    {
        return (new ExecutableFinder)->find('metaflac');
    }
}
