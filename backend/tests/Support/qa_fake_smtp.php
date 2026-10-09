<?php

/** QA BE-backlog-1: SMTP giả từ chối MỌI người nhận và nhắc lại địa chỉ trong thông điệp (như máy chủ thật). Dùng: php qa_fake_smtp.php <port> */
$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "listen fail\n");
    exit(1);
}
while ($conn = @stream_socket_accept($server, 120)) {
    fwrite($conn, "220 fake.local ESMTP\r\n");
    while (($line = fgets($conn)) !== false) {
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($conn, "250 fake.local\r\n");
        } elseif ($cmd === 'MAIL') {
            fwrite($conn, "250 2.1.0 OK\r\n");
        } elseif ($cmd === 'RCPT') {
            preg_match('/<([^>]*)>/', $line, $m);
            fwrite($conn, "550 5.1.1 <{$m[1]}>: Recipient address rejected: User unknown\r\n");
        } elseif ($cmd === 'RSET') {
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($conn, "221 Bye\r\n");
            break;
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
}
