#Pawl

An asynchronous PHP WebSocket client. (early alpha)

---

```php
<?php

    require __DIR__ . '/vendor/autoload.php';

    $loop = React\EventLoop\Factory::create();
    $connector = new Ratchet\Client\Factory($loop);

    $connector('ws://127.0.0.1:8080')->then(function(Ratchet\Client\WebSocket $conn) {
        $conn->on('message', function($msg) {
            echo "Received: {$msg}\n";
        });

        $conn->send('Hello World!');
    }, function($e) use ($loop) {
        echo "Could not connect: {$e->getMessage()}\n";
        $loop->stop();
    });

    $loop->run();
```
