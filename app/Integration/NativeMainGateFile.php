<?php

namespace Ulaxys\Identity\Integration;

/** Only the reviewed MainGate renderer may produce non-JSON bundle entries. */
final readonly class NativeMainGateFile
{
    private function __construct(public string $path, public string $contents) {}

    public static function fromRenderer(string $path, string $contents): self
    {
        if (! preg_match('~^gateway/maingate/(?:controllers/[A-Za-z][A-Za-z0-9_]*Controller\.php|routes/[A-Za-z][A-Za-z0-9_-]*(?:/[A-Za-z][A-Za-z0-9_-]*)?\.php|README\.md)$~D', $path)) {
            throw new IntegrationException('Unapproved native MainGate output path.');
        }
        if (str_ends_with($path, '.php')) {
            try {
                token_get_all($contents, TOKEN_PARSE);
            } catch (\ParseError $error) {
                throw new IntegrationException('Invalid generated PHP: '.$path, 0, $error);
            }
        }

        return new self($path, $contents);
    }
}
