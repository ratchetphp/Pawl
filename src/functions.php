<?php
namespace Ratchet\Client;
use React\Dns\Resolver\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as ReactFactory;

/**
 * @param string             $url
 * @param array              $subProtocols
 * @param array              $headers
 * @param LoopInterface|null $loop
 * @param null               $dnsServerAddress
 * @return \React\Promise\PromiseInterface
 */
function connect($url, array $subProtocols = [], $headers = [], LoopInterface $loop = null, $dnsServerAddress = null) {
    $loop = $loop ?: ReactFactory::create();

    if (null !== $dnsServerAddress) {
        $dnsFactory = new Factory();
        $resolver = $dnsFactory->create($dnsServerAddress, $loop);
        $connector = new Connector($loop, $resolver);
    } else {
        $connector = new Connector($loop);
    }
    $connection = $connector($url, $subProtocols, $headers);

    register_shutdown_function(function() use ($loop) {
        $loop->run();
    });

    return $connection;
}
