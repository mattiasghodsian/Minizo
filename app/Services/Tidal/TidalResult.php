<?php

namespace App\Services\Tidal;

final readonly class TidalResult
{
    /**
     * One answer from Tidal, or why there is not one.
     *
     * The failure used to be a null return plus a bare `int 401` sentinel, which made it
     * optional to read - that is how a rejected token surfaced as "did not respond".
     *
     * @param  array<string, mixed>|null  $body  null when the call failed
     * @param  int|null  $status  null when the request never reached Tidal
     * @param  string|null  $detail  Tidal's errors.0.detail, or the transport error
     */
    private function __construct(
        public ?array $body,
        public ?int $status,
        public ?string $detail,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function document(array $body, int $status): self
    {
        return new self(body: $body, status: $status, detail: null);
    }

    /** Tidal answered, and the answer was no. */
    public static function rejected(int $status, ?string $detail): self
    {
        return new self(body: null, status: $status, detail: $detail);
    }

    /** No HTTP answer at all - DNS, TLS, timeout. */
    public static function unreachable(string $error): self
    {
        return new self(body: null, status: null, detail: $error);
    }

    public function succeeded(): bool
    {
        return $this->body !== null;
    }

    /** The one status that is an answer, not a fault: an artist with no albums. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** One line for logs, the console and the operator half of an exception. */
    public function summary(): string
    {
        // Naming a number when none arrived would be a lie.
        if ($this->status === null) {
            return 'could not reach Tidal: '.($this->detail ?? 'no further detail');
        }

        return 'HTTP '.$this->status.($this->detail !== null ? ' — '.$this->detail : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['status' => $this->status, 'detail' => $this->detail];
    }
}
