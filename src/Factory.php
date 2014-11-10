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
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Ratchet\WebSocket\Version\RFC6455;

class Factory
{
    /**
     * @var LoopInterface
     */
    protected $_loop;

    /**
     * @var Connector
     */
    protected $_connector;

    /**
     * @var SecureConnector
     */
    protected $_secureConnector;

    public $defaultHeaders = [
        'Connection'            => 'Upgrade',
        'Cache-Control'         => 'no-cache',
        'Pragma'                => 'no-cache',
        'Upgrade'               => 'websocket',
        'Sec-WebSocket-Version' => 13,
        'User-Agent'            => "Ratchet->Pawl/0.0.1"
    ];

    public function __construct(LoopInterface $loop, Resolver $resolver = null)
	{
        if (null === $resolver) {
            $factory  = new DnsFactory();
            $resolver = $factory->create('8.8.8.8', $loop);
        }

        $this->_loop            = $loop;
        $this->_connector       = new Connector($loop, $resolver);
        $this->_secureConnector = new SecureConnector($this->_connector, $loop);
    }

    public function __invoke($url, array $subProtocols = [], array $headers = [])
    {
        try {
            $request = $this->generateRequest($url, $subProtocols, $headers);
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }

        $connector = 'wss' === substr($url, 0, 3) ? $this->_secureConnector : $this->_connector;

        return $connector->create($request->getHost(), $request->getPort())->then(function(DuplexStreamInterface $stream) use ($request, $subProtocols) {
            $futureWsConn = new Deferred;

            $buffer = '';
            $headerParser = function($data, DuplexStreamInterface $stream) use (&$headerParser, &$buffer, $futureWsConn, $request, $subProtocols) {
                $buffer .= $data;

                if (false == strpos($buffer, "\r\n\r\n")) {
                    return;
                }

                $stream->removeListener('data', $headerParser);

                $response = Response::fromMessage($buffer);

                if (101 !== $response->getStatusCode()) {
                    $futureWsConn->reject($response);
                    $stream->close();

                    return;
                }

                $acceptCheck = base64_encode(pack('H*', sha1($request->getHeader('Sec-WebSocket-Key') . RFC6455::GUID)));

                if ((string)$response->getHeader('Sec-WebSocket-Accept') !== $acceptCheck) {
                    $futureWsConn->reject(new \DomainException('Could not verify Accept Key during WebSocket handshake'));
                    $stream->close();

                    return;
                }

                $acceptedProtocol = $response->getHeader('Sec-WebSocket-Protocol');

                if ((count($subProtocols) > 0 || null !== $acceptedProtocol) && !in_array((string)$acceptedProtocol, $subProtocols)) {
                    $futureWsConn->reject(new \DomainException('Server did not respond with an expected Sec-WebSocket-Protocol'));
                    $stream->close();
                    
                    return;
                }

                $futureWsConn->resolve(new WebSocket($stream, $response, $request));

                $futureWsConn->promise()->then(
                    function(WebSocket $conn) use ($stream) {
                        $stream->emit('data', [$conn->response->getBody(), $stream]);
                    }
                );
            };

            $stream->on('data', $headerParser);
            $stream->write($request);

            return $futureWsConn->promise();
        });
    }

    /**
     * @param string $url
     * @param array  $subProtocols
     * @param array  $headers
     *
     * @return Request
     */
    protected function generateRequest($url, array $subProtocols, array $headers)
    {
        $headers = array_merge($this->defaultHeaders, $headers);
        $headers['Sec-WebSocket-Key'] = $this->generateKey();

        $request = new Request('GET', $url, $headers);

        $scheme = strtolower($request->getScheme());

        if (!in_array($scheme, ['ws', 'wss'])) {
            throw new \InvalidArgumentException(sprintf('Cannot connect to invalid URL (%s)', $url));
        }

        $request->setScheme('HTTP');

        if (!$request->getPort()) {
            $request->setPort('wss' === $scheme ? 443 : 80);
        } else {
            $request->setHeader('Host', $request->getHeader('Host') . ":{$request->getPort()}");
        }

        if (!$request->getHeader('Origin')) {
            $request->setHeader('Origin', str_replace('ws', 'http', $scheme) . '://' . $request->getHost());
        }

        // do protocol headers
        if (count($subProtocols) > 0) {
            $protocols = implode(',', $subProtocols);

            if ($protocols !== '') {
                $request->setHeader('Sec-WebSocket-Protocol', $protocols);
            }
        }

        return $request;
    }

    /**
     * @return string
     */
	protected function generateKey()
	{
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwzyz1234567890+/=';
        $charRange = strlen($chars) - 1;
        $key = '';

        for ($i = 0;$i < 16;$i++) {
            $key .= $chars[mt_rand(0, $charRange)];
        }

        return base64_encode($key);
    }
}
