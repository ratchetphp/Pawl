<?php
namespace Ratchet\Client;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexStreamInterface;
use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory as DnsFactory;
use React\Promise\Deferred;
use React\Promise\RejectedPromise;
use GuzzleHttp\Psr7 as gPsr;
use Ratchet\RFC6455\Handshake\NegotiatorInterface;

class Factory {
    protected $_loop;
    protected $_connector;
    protected $_secureConnector;

    public $defaultHeaders = [
        'Connection'            => 'Upgrade'
      , 'Cache-Control'         => 'no-cache'
      , 'Pragma'                => 'no-cache'
      , 'Upgrade'               => 'websocket'
      , 'Sec-WebSocket-Version' => 13
      , 'User-Agent'            => "Ratchet->Pawl/0.0.2"
    ];

    public function __construct(LoopInterface $loop, Resolver $resolver = null) {
        if (null === $resolver) {
            $factory  = new DnsFactory();
            $resolver = $factory->create('8.8.8.8', $loop);
        }

        $this->_loop            = $loop;
        $this->_connector       = new Connector($loop, $resolver);
        $this->_secureConnector = new SecureConnector($this->_connector, $loop);
    }

    public function __invoke($url, array $subProtocols = [], array $headers = []) {
        try {
            $request = $this->generateRequest($url, $subProtocols, $headers);
            $uri = $request->getUri();
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
        $connector = 'wss' === substr($url, 0, 3) ? $this->_secureConnector : $this->_connector;

        return $connector->create($uri->getHost(), $uri->getPort())->then(function(DuplexStreamInterface $stream) use ($request, $subProtocols) {
            $futureWsConn = new Deferred;

            $buffer = '';
            $headerParser = function($data, DuplexStreamInterface $stream) use (&$headerParser, &$buffer, $futureWsConn, $request, $subProtocols) {
                $buffer .= $data;
                if (false == strpos($buffer, "\r\n\r\n")) {
                    return;
                }

                $stream->removeListener('data', $headerParser);

                $response = gPsr\parse_response($buffer);

                if (101 !== $response->getStatusCode()) {
                    $futureWsConn->reject($response);
                    $stream->close();

                    return;
                }

                $acceptCheck = base64_encode(pack('H*', sha1($request->getHeader('Sec-WebSocket-Key')[0] . NegotiatorInterface::GUID)));
                if ((string)$response->getHeader('Sec-WebSocket-Accept')[0] !== $acceptCheck) {
                    $futureWsConn->reject(new \DomainException("Could not verify Accept Key during WebSocket handshake"));
                    $stream->close();

                    return;
                }

                $acceptedProtocol = $response->getHeader('Sec-WebSocket-Protocol');
                if ((count($subProtocols) > 0) && !in_array((string)$acceptedProtocol, $subProtocols)) {
                    $futureWsConn->reject(new \DomainException('Server did not respond with an expected Sec-WebSocket-Protocol'));
                    $stream->close();

                    return;
                }

                $futureWsConn->resolve(new WebSocket($stream, $response, $request));

                $futureWsConn->promise()->then(function(WebSocket $conn) use ($stream) {
                    $stream->emit('data', [$conn->response->getBody(), $stream]);
                });
            };

            $stream->on('data', $headerParser);
            $stream->write(gPsr\str($request));

            return $futureWsConn->promise();
        });
    }

    protected function generateRequest($url, array $subProtocols, array $headers) {
        $headers = array_merge($this->defaultHeaders, $headers);
        $headers['Sec-WebSocket-Key'] = $this->generateKey();

        $request = new gPsr\Request('GET', $url, $headers);
        $uri = $request->getUri();

        $scheme = $uri->getScheme();

        if (!in_array($scheme, ['ws', 'wss'])) {
            throw new \InvalidArgumentException(sprintf('Cannot connect to invalid URL (%s)', $url));
        }

        $uri = $uri->withScheme('HTTP');

        if (!$uri->getPort()) {
            $uri = $uri->withtPort('wss' === $scheme ? 443 : 80);
        } else {
            $request = $request->withHeader('Host', $request->getHeader('Host')[0] . ":{$uri->getPort()}");
        }

        if (!$request->getHeader('Origin')) {
            $request = $request->withHeader('Origin', str_replace('ws', 'http', $scheme) . '://' . $uri->getHost());
        }

        // do protocol headers
        if (count($subProtocols) > 0) {
            $protocols = implode(',', $subProtocols);
            if ($protocols != "") {
                $request = $request->withHeader('Sec-WebSocket-Protocol', $protocols);
            }
        }

        return $request;
    }

    protected function generateKey() {
        $chars     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwzyz1234567890+/=';
        $charRange = strlen($chars) - 1;
        $key       = '';

        for ($i = 0;$i < 16;$i++) {
            $key .= $chars[mt_rand(0, $charRange)];
        }

        return base64_encode($key);
    }
}
