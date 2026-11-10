<?php

namespace Modules\Addons\Pec;

use RuntimeException;

/**
 * The few IMAP commands reading PEC receipts takes: log in, open the inbox,
 * find messages by header, fetch one whole. Written here so the addon needs
 * neither the php-imap extension (gone from PHP 8.4) nor a new package.
 */
class ImapClient implements ReceiptMailbox
{
    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private int $timeout = 20,
    ) {}

    public function open(): void
    {
        $stream = @stream_socket_client('ssl://'.$this->host.':'.$this->port, $errno, $error, $this->timeout);
        if ($stream === false) {
            throw new RuntimeException("IMAP {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = $this->line();
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('IMAP: unexpected greeting');
        }

        $this->command('LOGIN '.$this->quote($this->username).' '.$this->quote($this->password));
        $this->command('SELECT INBOX');
    }

    /** @return list<int> UIDs of the messages whose header carries the value */
    public function search(string $header, string $value): array
    {
        $lines = $this->command('UID SEARCH HEADER '.$this->quote($header).' '.$this->quote($value));
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                return array_map('intval', array_values(array_filter(explode(' ', trim(substr($line, 8))), fn (string $uid) => $uid !== '')));
            }
        }

        return [];
    }

    /** The whole message, headers and body, as it lies in the mailbox. */
    public function fetch(int $uid): string
    {
        $tag = $this->send('UID FETCH '.$uid.' (BODY.PEEK[])');
        $message = '';

        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $message = $this->read((int) $m[1]);

                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                $this->expectOk($line);

                return $message;
            }
        }
    }

    public function close(): void
    {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (RuntimeException) {
                // Leaving anyway.
            }
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @return list<string> the untagged lines of the answer */
    private function command(string $command): array
    {
        $tag = $this->send($command);
        $lines = [];

        while (true) {
            $line = $this->line();
            if (str_starts_with($line, $tag.' ')) {
                $this->expectOk($line);

                return $lines;
            }
            $lines[] = $line;
        }
    }

    private function send(string $command): string
    {
        if (! $this->stream) {
            throw new RuntimeException('IMAP: not connected');
        }
        $tag = 'P'.(++$this->tag);
        fwrite($this->stream, $tag.' '.$command."\r\n");

        return $tag;
    }

    private function expectOk(string $line): void
    {
        if (! preg_match('/^\S+ OK/', $line)) {
            // The server's words, without the command they answer (it may hold the password).
            throw new RuntimeException('IMAP: '.trim((string) preg_replace('/^\S+ (NO|BAD) ?/', '', $line)));
        }
    }

    private function line(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new RuntimeException('IMAP: connection closed');
        }

        return rtrim($line, "\r\n");
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->stream, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP: connection closed');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
