<?php

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector;
use React\EventLoop\Factory;
use React\Promise\RejectedPromise;

class ConnectorTest extends TestCase
{
    public function uriDataProvider() {
        return [
            ['ws://127.0.0.1', 'tcp://127.0.0.1:80'],
            ['wss://127.0.0.1', 'tls://127.0.0.1:443'],
            ['ws://127.0.0.1:1234', 'tcp://127.0.0.1:1234'],
            ['wss://127.0.0.1:4321', 'tls://127.0.0.1:4321']
        ];
    }

    /**
     * @dataProvider uriDataProvider
     */
    public function testSecureConnectionUsesTlsScheme($uri, $expectedConnectorUri) {
        $loop = Factory::create();

        $connector = $this->getMock('React\Socket\ConnectorInterface');

        $connector->expects($this->once())
            ->method('connect')
            ->with($this->callback(function ($uri) use ($expectedConnectorUri) {
                return $uri === $expectedConnectorUri;
            }))
            // reject the promise so that we don't have to mock a connection here
            ->willReturn(new RejectedPromise(new Exception('')));

        $pawlConnector = new Connector($loop, $connector);

        $pawlConnector($uri);
    }
}
