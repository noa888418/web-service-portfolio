<?php

namespace Tests\Support;

final class AuthHttpEnvironment
{
    public array $urls = [];
    public string $clock;
    public string $barrier;
    public string $log;
    public string $scenario;
    private array $processes = [];

    public function __construct(string $schema)
    {
        $this->clock = tempnam(sys_get_temp_dir(), 'auth-clock-');
        $this->barrier = tempnam(sys_get_temp_dir(), 'auth-barrier-');
        $this->log = tempnam(sys_get_temp_dir(), 'auth-log-');
        $this->scenario = tempnam(sys_get_temp_dir(), 'request-scenario-');
        $this->time(1800000000);
        try {
            for ($i = 0; $i < 2; $i++) {
                $socket = stream_socket_server('tcp://127.0.0.1:0');
                $address = stream_socket_get_name($socket, false);
                fclose($socket);
                $env = array_merge(getenv(), ['AUTH_TEST_SCHEMA' => $schema, 'AUTH_TEST_CLOCK' => $this->clock, 'AUTH_TEST_BARRIER' => $this->barrier, 'AUTH_TEST_SCENARIO' => $this->scenario]);
                $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-S', $address, 'tests/http-router.php'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes, dirname(__DIR__, 2), $env);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Test HTTP server failed to start.');
                }
                $this->processes[] = $process;
                $this->urls[] = 'http://'.$address;
                $ready = false;
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
                    if ($connection) {
                        fclose($connection);
                        $ready = true;
                        break;
                    }
                    usleep(20000);
                }
                if (! $ready) {
                    throw new \RuntimeException('Test HTTP server did not become ready.');
                }
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function time(int $timestamp): void
    {
        file_put_contents($this->clock, (string) $timestamp, LOCK_EX);
    }

    public function close(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->processes = [];
        foreach ([$this->clock, $this->barrier, $this->barrier.'.entered', $this->barrier.'.release', $this->log, $this->scenario] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
