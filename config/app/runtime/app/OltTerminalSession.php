<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/driver/Controller.php';

final class OltTerminalSession
{
    private string $pending = '';
    private bool $naws = false;
    private int $cols = 100;
    private int $rows = 28;

    
    public function __construct(private $socket)
    {
        stream_set_blocking($this->socket, false);
        
        $this->writeBytes("\xFF\xFB\x1F");
    }

    public static function open(array $endpoint): self
    {
        $driverId = trim((string)($endpoint['driver_id'] ?? ''));
        $driver = (new \App\Driver\Controller())->createCli($driverId, $endpoint);
        if (!$driver instanceof OltTerminalCliClient) throw new RuntimeException('Klien terminal belum memenuhi kontrak OLT.');
        try {
            $login = $driver->connectForTerminal();
            if (empty($login['ok'])) throw new RuntimeException((string)($login['message'] ?? 'Login Telnet gagal.'));
            $socket = $driver->detachTerminalSocket();
            if (!is_resource($socket)) throw new RuntimeException('Koneksi terminal tidak tersedia.');
            $terminal = new self($socket);
            $terminal->write("\r");
            return $terminal;
        } finally {
            $driver->close();
        }
    }

    public function active(): bool
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    public function write(string $data): void
    {
        
        $data = str_replace("\r\n", "\r", $data);
        $this->writeBytes(str_replace("\xFF", "\xFF\xFF", str_replace("\r", "\r\n", $data)));
    }

    private function writeBytes(string $data): void
    {
        $deadline = microtime(true) + 1;
        while ($data !== '') {
            if (!$this->active()) throw new RuntimeException('Koneksi terminal terputus.');
            $written = @fwrite($this->socket, $data);
            if ($written === false) throw new RuntimeException('Perintah tidak dapat dikirim.');
            $data = substr($data, $written);
            if ($data === '') break;
            if (microtime(true) >= $deadline) throw new RuntimeException('Pengiriman perintah melewati batas waktu.');
            $read = null; $write = [$this->socket]; $except = null;
            @stream_select($read, $write, $except, 0, 20000);
        }
    }

    public function resize(int $cols, int $rows): void
    {
        $this->cols = max(20, min(300, $cols));
        $this->rows = max(5, min(100, $rows));
        if ($this->naws) {
            $size = str_replace("\xFF", "\xFF\xFF", pack('nn', $this->cols, $this->rows));
            $this->writeBytes("\xFF\xFA\x1F" . $size . "\xFF\xF0");
        }
    }

    public function read(): string
    {
        $output = '';
        $deadline = microtime(true) + 0.25;
        while ($this->active() && strlen($output) < 65536 && microtime(true) < $deadline) {
            $read = [$this->socket]; $write = null; $except = null;
            if (@stream_select($read, $write, $except, 0, 10000) < 1) break;
            $chunk = @fread($this->socket, 8192);
            if (!is_string($chunk) || $chunk === '') break;
            $output .= $this->consume($chunk);
        }
        return $output;
    }

    
    private function consume(string $chunk): string
    {
        $data = $this->pending . $chunk;
        $this->pending = '';
        $output = '';
        $length = strlen($data);
        for ($i = 0; $i < $length;) {
            if ($data[$i] !== "\xFF") {
                if ($data[$i] !== "\0") $output .= $data[$i];
                $i++;
                continue;
            }
            if ($i + 1 >= $length) break;
            $command = ord($data[$i + 1]);
            if ($command === 255) { $output .= "\xFF"; $i += 2; continue; }
            if ($command === 250) {
                $end = strpos($data, "\xFF\xF0", $i + 2);
                if ($end === false) break;
                $sub = substr($data, $i + 2, $end - $i - 2);
                if ($sub === "\x18\x01") $this->writeBytes("\xFF\xFA\x18\x00xterm\xFF\xF0");
                if ($sub === "\x20\x01") $this->writeBytes("\xFF\xFA\x20\x0038400,38400\xFF\xF0");
                $i = $end + 2;
                continue;
            }
            if (in_array($command, [251, 252, 253, 254], true)) {
                if ($i + 2 >= $length) break;
                $option = ord($data[$i + 2]);
                if ($command === 253) {
                    $accept = in_array($option, [3, 24, 31, 32], true);
                    $this->writeBytes("\xFF" . chr($accept ? 251 : 252) . chr($option));
                    if ($option === 31) { $this->naws = true; $this->resize($this->cols, $this->rows); }
                } elseif ($command === 251) {
                    $this->writeBytes("\xFF" . chr(in_array($option, [1, 3], true) ? 253 : 254) . chr($option));
                } elseif ($command === 254 && $option === 31) {
                    $this->naws = false;
                }
                $i += 3;
                continue;
            }
            $i += 2;
        }
        if ($i < $length) $this->pending = substr($data, $i);
        if (strlen($this->pending) > 8192) throw new RuntimeException('Negosiasi Telnet tidak valid.');
        return $output;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) @fclose($this->socket);
        $this->socket = null;
    }

    public function __destruct() { $this->close(); }
}
