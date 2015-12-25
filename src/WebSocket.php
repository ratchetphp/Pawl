<?php
namespace Ratchet\Client;
use Evenement\EventEmitterTrait;
use Evenement\EventEmitterInterface;
use React\Stream\DuplexStreamInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Messaging\Streaming\MessageStreamer;
use Ratchet\RFC6455\Encoding\Validator;
use Ratchet\RFC6455\Messaging\Protocol\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;
use Ratchet\RFC6455\Messaging\Protocol\Frame;

class WebSocket implements EventEmitterInterface {
    use EventEmitterTrait;

    /**
     * The request headers sent to establish the connection
     * @var \Psr\Http\Message\RequestInterface
     */
    public $request;

    /**
     * The response headers received from the server to establish the connection
     * @var \Psr\Http\Message\ResponseInterface
     */
    public $response;

    /**
     * @var \React\Stream\Stream
     */
    protected $_stream;

    /**
     * WebSocket constructor.
     * @param \React\Stream\DuplexStreamInterface $stream
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param \Psr\Http\Message\RequestInterface  $request
     * @event message
     * @event end
     * @event close
     * @event error
     */
    public function __construct(DuplexStreamInterface $stream, ResponseInterface $response, RequestInterface $request) {
        $this->_stream  = $stream;
        $this->response = $response;
        $this->request  = $request;

        $reusableUAException = new \UnderflowException;

        $streamer = new MessageStreamer(
            new Validator,
            new CloseFrameChecker,
            function(MessageInterface $msg) {
                $this->emit('message', [$msg, $this]);
            },
            function(FrameInterface $frame) use (&$streamer) {
                switch ($frame->getOpcode()) {
                    case Frame::OP_CLOSE:
                        $this->close($frame->getPayload());

                        return;
                    case Frame::OP_PING:
                        $this->send(new $streamer->newFrame($frame->getPayload(), true, Frame::OP_PONG));
                        break;
                    case Frame::OP_PONG:
                        $this->emit('pong', [$frame, $this]);
                        break;
                    default:
                        $this->close($frame->getPayload());
                        return;
                }
            },
            false,
            function() use ($reusableUAException) {
                return $reusableUAException;
            }
        );

        $stream->on('data', [$streamer, 'onData']);

        $stream->on('end', function(DuplexStreamInterface $stream) {
            if (is_resource($stream->stream)) {
                stream_socket_shutdown($stream->stream, STREAM_SHUT_RDWR);
                stream_set_blocking($stream->stream, false);
            }
        });

        $stream->on('close', function() {
            $this->emit('close', [$this]);
        });

        $stream->on('error', function($error) {
            $this->emit('error', [$error, $this]);
        });
    }

    public function send($msg) {
        if ($msg instanceof Frame) {
            $frame = $msg;
        } else {
            $frame = new Frame($msg);
        }
        $frame->maskPayload($frame->generateMaskingKey());

        $this->_stream->write($frame->getContents());
    }

    public function close($code = 1000) {
        $frame = new Frame(pack('n', $code), true, Frame::OP_CLOSE);

        $this->_stream->write($frame->getContents());
        $this->_stream->end();
    }
}
