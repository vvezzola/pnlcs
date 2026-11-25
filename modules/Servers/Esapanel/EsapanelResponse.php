<?php

namespace Modules\Servers\Esapanel;

/**
 * One answer from an Esapanel server.
 *
 * status 0 means the server was not reached at all (network, TLS, timeout).
 */
final class EsapanelResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?array $data = null,
        public readonly string $error = '',
        public readonly array $meta = [],
    ) {}
}
