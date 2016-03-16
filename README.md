#Pawl

[![Autobahn Testsuite](https://img.shields.io/badge/Autobahn-passing-brightgreen.svg)](http://socketo.me/reports/pawl/index.html)

An asynchronous PHP WebSocket client

---
Using Pawl as a standalone app: Connect to an echo server, send a message, display output back, close connection.
```php
<?php

    require __DIR__ . '/vendor/autoload.php';

    \Ratchet\Client\connect('ws://echo.socketo.me:9000')->then(function($conn) {
        $conn->on('message', function($msg) use ($conn) {
            echo "Received: {$msg}\n";
            $conn->close();
        });

        $conn->send('Hello World!');
    }, function ($e) {
        echo "Could not connect: {$e->getMessage()}\n";
    });
```

Using the components of Pawl: Requesting sub-protocols, and sending custom headers while using a specific React Event Loop.
```php
<?php

    require __DIR__ . '/vendor/autoload.php';

    $loop = React\EventLoop\Factory::create();
    $connector = new Ratchet\Client\Connector($loop);

    $connector('ws://127.0.0.1:9000', ['protocol1', 'subprotocol2'], ['Origin' => 'http://localhost'])
    ->then(function(Ratchet\Client\WebSocket $conn) {
        $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn) {
            echo "Received: {$msg}\n";
            $conn->close();
        });

        $conn->on('close', function($code = null, $reason = null) {
            echo "Connection closed ({$code} - {$reason})\n";
        });

        $conn->send('Hello World!');
    }, function(\Exception $e) use ($loop) {
        echo "Could not connect: {$e->getMessage()}\n";
        $loop->stop();
    });

    $loop->run();
```
