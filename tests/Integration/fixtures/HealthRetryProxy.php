<?php

declare(strict_types=1);

// A real TCP reset after a warm HTTP/2 connection, without stopping the backend.
$listener = stream_socket_server('tcp://' . $argv[1]);
if ($listener === false) {
    exit(1);
}
file_put_contents($argv[3] . '/proxy-ready', '1');
/** @var array<int, array{stream: resource, peer: resource, client: bool}> $connections */
$connections = [];
while (true) {
    $read = [$listener];
    foreach ($connections as $connection) {
        $read[] = $connection['stream'];
    }
    $write = $except = null;
    if (stream_select($read, $write, $except, 1) === false) {
        break;
    }
    foreach ($read as $stream) {
        if ($stream === $listener) {
            $client = stream_socket_accept($listener, 0);
            $backend = stream_socket_client('tcp://' . $argv[2]);
            if ($client === false || $backend === false) {
                exit(2);
            }
            $connections[(int)$client] = ['stream' => $client, 'peer' => $backend, 'client' => true];
            $connections[(int)$backend] = ['stream' => $backend, 'peer' => $client, 'client' => false];
            continue;
        }
        if (!isset($connections[(int)$stream])) {
            continue;
        }
        $connection = $connections[(int)$stream];
        $data = fread($stream, 65536);
        $reset = $connection['client'] && is_file($argv[3] . '/reset');
        if ($reset) {
            unlink($argv[3] . '/reset');
            $socket = socket_import_stream($stream);
            if ($socket !== false) {
                socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            }
        }
        if ($reset || $data === false || $data === '') {
            unset($connections[(int)$stream], $connections[(int)$connection['peer']]);
            fclose($connection['peer']);
            fclose($stream);
            continue;
        }
        fwrite($connection['peer'], $data);
    }
}
