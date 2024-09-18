<?php

$servers = [
    ['hostname' => 'server1', 'username' => 'user1', 'password' => 'password1'],
    ['hostname' => 'server2', 'username' => 'user2', 'password' => 'password2'],
    ['hostname' => 'server3', 'username' => 'user3', 'password' => 'password3'],
];

$saveUrl = 'https://yourservice.com/api/save';

// Увеличиваем лимит памяти и времени выполнения скрипта
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300'); // 300 секунд (5 минут)

// Функция для чтения syslog с сервера по SSH
function fetchSyslog($server, $chunkSize = 1024 * 1024) {
    $connection = ssh2_connect($server['hostname']);
    if (!$connection) {
        throw new Exception("Не удалось подключиться к {$server['hostname']}");
    }

    if (!ssh2_auth_password($connection, $server['username'], $server['password'])) {
        throw new Exception("Не удалось авторизоваться на {$server['hostname']}");
    }

    $stream = ssh2_exec($connection, 'cat /var/log/syslog'); // путь к файлу может отличаться
    if (!$stream) {
        throw new Exception("Не удалось выполнить команду на {$server['hostname']}");
    }

    stream_set_blocking($stream, true);

    // Чтение данных по частям
    $syslogData = '';
    while ($chunk = fread($stream, $chunkSize)) {
        $syslogData .= $chunk;
    }

    fclose($stream);
    return $syslogData;
}

// Функция для отправки данных на сервис
function sendToService($data) {
    global $saveUrl;

    // Сжимаем данные для отправки
    $compressedData = gzencode(json_encode(['syslog' => $data]));

    $ch = curl_init($saveUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $compressedData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Encoding: gzip',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('Ошибка CURL: ' . curl_error($ch));
    }

    curl_close($ch);

    return [$httpCode, json_decode($response, true)];
}

// Основной цикл обработки каждого сервера
foreach ($servers as $server) {
    try {
        echo "Обработка сервера {$server['hostname']}...\n";
        
        // Получаем данные syslog
        $syslogData = fetchSyslog($server);
        echo "Лог с сервера {$server['hostname']} получен, длина данных: " . strlen($syslogData) . " байт.\n";
        
        // Отправляем данные на удалённый сервис
        list($statusCode, $response) = sendToService($syslogData);
        echo "Данные с {$server['hostname']} отправлены со статусом $statusCode: " . json_encode($response) . "\n";
    } catch (Exception $e) {
        echo "Ошибка обработки {$server['hostname']}: " . $e->getMessage() . "\n";
    }
}
