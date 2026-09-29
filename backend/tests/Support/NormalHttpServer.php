<?php

namespace Tests\Support;

final class NormalHttpServer
{
    public string $url;
    public string $log;
    private $process;

    public function __construct(string $schema)
    {
        TestDatabaseGuard::connect();
        if (! preg_match('/\Ausers_test_[a-f0-9]{24}\z/D', $schema)) { throw new \RuntimeException('Normal HTTP test schema refused.'); }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $this->url = 'http://'.$address;
        $this->log = tempnam(sys_get_temp_dir(), 'normal-http-');
        $env = array_merge(getenv(), ['APP_ENV' => 'local', 'DB_SCHEMA' => $schema, 'APP_DEBUG' => 'false', 'SESSION_SECURE_COOKIE' => 'false']);
        $this->process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-S', $address, '-t', 'public', 'public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes, dirname(__DIR__, 2), $env);
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
            if ($connection) { fclose($connection); return; }
            usleep(20000);
        }
        $this->close(); throw new \RuntimeException('Normal HTTP server did not start.');
    }

    public function close(): void
    {
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); }
        if (is_file($this->log)) { unlink($this->log); }
    }
}
