<?php

ini_set('memory_limit', '256M'); // Увеличьте значение при необходимости

$servers = [
    ['hostname' => 'server1', 'username' => 'user1', 'password' => 'password1'],
    ['hostname' => 'server2', 'username' => 'user2', 'password' => 'password2'],
    ['hostname' => 'server3', 'username' => 'user3', 'password' => 'password3'],
];

$saveUrl = 'https://yourservice.com/api/save';

function fetchSyslog($server) {
    $connection = ssh2_connect($server['hostname']);
    ssh2_auth_password($connection, $server['username'], $server['password']);

    $stream = ssh2_exec($connection, 'cat /var/log/syslog'); // путь к файлу может отличаться
    stream_set_blocking($stream, true);
    $syslogData = stream_get_contents($stream);
    fclose($stream);

    return $syslogData;
}

function sendToService($data) {
    global $saveUrl;

    $ch = curl_init($saveUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['syslog' => $data]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, json_decode($response, true)];
}

foreach ($servers as $server) {
    try {
        $syslogData = fetchSyslog($server);
        list($statusCode, $response) = sendToService($syslogData);
        echo "Data from {$server['hostname']} sent with status $statusCode: " . json_encode($response) . "\n";
    } catch (Exception $e) {
        echo "Error processing {$server['hostname']}: " . $e->getMessage() . "\n";
    }
}

