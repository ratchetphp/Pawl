<?php
namespace Ratchet\Client;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexStreamInterface;
use React\SocketClient\Connector as SocketConnector;
use React\SocketClient\SecureConnector;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory as DnsFactory;
use React\Promise\Deferred;
use React\Promise\RejectedPromise;
use GuzzleHttp\Psr7 as gPsr;

class Connector {
    protected $_loop;
    protected $_connector;
    protected $_secureConnector;
    protected $_negotiator;

    public function __construct(LoopInterface $loop, Resolver $resolver = null) {
        if (null === $resolver) {
            $factory  = new DnsFactory();
            $resolver = $factory->create('8.8.8.8', $loop);
        }

        $this->_loop            = $loop;
        $this->_connector       = new SocketConnector($loop, $resolver);
        $this->_secureConnector = new SecureConnector($this->_connector, $loop);
        $this->_negotiator      = new ClientNegotiator;
    }

    /**
     * @param string $url
     * @param array  $subProtocols
     * @param array  $headers
     * @return \React\Promise\PromiseInterface
     */
    public function __invoke($url, array $subProtocols = [], array $headers = []) {
        try {
            $request = $this->generateRequest($url, $subProtocols, $headers);
            $uri = $request->getUri();
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
        $connector = 'wss' === substr($url, 0, 3) ? $this->_secureConnector : $this->_connector;

        $port = $uri->getPort() ?: 80;

        return $connector->create($uri->getHost(), $port)->then(function(DuplexStreamInterface $stream) use ($request, $subProtocols) {
            $futureWsConn = new Deferred;

            $earlyClose = function() use ($futureWsConn) {
                $futureWsConn->reject(new \RuntimeException('Connection closed before handshake'));
            };

            $stream->on('close', $earlyClose);
            $futureWsConn->promise()->then(function() use ($stream, $earlyClose) {
                $stream->removeListener('close', $earlyClose);
            });

            $buffer = '';
            $headerParser = function($data, DuplexStreamInterface $stream) use (&$headerParser, &$buffer, $futureWsConn, $request, $subProtocols) {
                $buffer .= $data;
                if (false == strpos($buffer, "\r\n\r\n")) {
                    return;
                }

                $stream->removeListener('data', $headerParser);

                $response = gPsr\parse_response($buffer);

                if (!$this->_negotiator->validateResponse($request, $response)) {
                    $futureWsConn->reject(new \DomainException(gPsr\str($response)));
                    $stream->close();

                    return;
                }

                $acceptedProtocol = $response->getHeader('Sec-WebSocket-Protocol');
                if ((count($subProtocols) > 0) && 1 !== count(array_intersect($subProtocols, $acceptedProtocol))) {
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

    /**
     * @param string $url
     * @param array  $subProtocols
     * @param array  $headers
     * @return \Psr\Http\Message\RequestInterface
     */
    protected function generateRequest($url, array $subProtocols, array $headers) {
        $uri = gPsr\uri_for($url);

        $scheme = $uri->getScheme();

        if (!in_array($scheme, ['ws', 'wss'])) {
            throw new \InvalidArgumentException(sprintf('Cannot connect to invalid URL (%s)', $url));
        }

        $uri = $uri->withScheme('HTTP');

        if (!$uri->getPort()) {
            $uri = $uri->withPort('wss' === $scheme ? 443 : 80);
        }

        $headers += ['User-Agent' => 'Ratchet-Pawl/0.2.2'];

        $request = array_reduce(array_keys($headers), function($request, $header) use ($headers) {
            return $request->withHeader($header, $headers[$header]);
        }, $this->_negotiator->generateRequest($uri));

        if (!$request->getHeader('Origin')) {
            $request = $request->withHeader('Origin', str_replace('ws', 'http', $scheme) . '://' . $uri->getHost());
        }

        if (count($subProtocols) > 0) {
            $protocols = implode(',', $subProtocols);
            if ($protocols != "") {
                $request = $request->withHeader('Sec-WebSocket-Protocol', $protocols);
            }
        }

        return $request;
    }
}
