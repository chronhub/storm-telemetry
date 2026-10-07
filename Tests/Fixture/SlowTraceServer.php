<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}
$address = stream_socket_get_name($server, false);
if ($address === false) {
    exit(1);
}
echo 'READY '.substr($address, strrpos($address, ':') + 1)."\n";
flush();
while (($client = stream_socket_accept($server, 5)) !== false) {
    echo "REQUEST\n";
    flush();
    if (($argv[1] ?? '') === 'retry') {
        fwrite($client, "HTTP/1.1 503 Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        continue;
    }
    if (($argv[1] ?? '') === 'split') {
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n");
        fflush($client);
        usleep(20000);
        fwrite($client, 'ok');
        fclose($client);

        break;
    }
    sleep(2);
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($client);
    break;
}
fclose($server);
