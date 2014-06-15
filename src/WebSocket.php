<?php
namespace Ratchet\Client;
use Evenement\EventEmitterTrait;
use Evenement\EventEmitterInterface;
use Ratchet\ConnectionInterface;
use React\Stream\Stream;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Ratchet\WebSocket\Version\RFC6455\Message;
use Ratchet\WebSocket\Version\RFC6455\Frame;

class WebSocket implements EventEmitterInterface, ConnectionInterface {
    use EventEmitterTrait;

    /**
     * The request headers sent to establish the connection
     * @var \Guzzle\Http\Message\Request
     */
    public $request;

    /**
     * The response headers received from the server to establish the connection
     * @var \Guzzle\Http\Message\Response
     */
    public $response;

    /**
     * @var \React\Stream\Stream
     */
    protected $_stream;

    /**
     * @var Ratchet\WebSocket\Version\RFC6455\Message
     */
    private $_message;

    /**
     * @var Ratchet\WebSocket\Version\RFC6455\Frame
     */
    private $_frame;

    public function __construct(Stream $stream, Response $response, Request $request) {
        $this->_stream  = $stream;
        $this->response = $response;
        $this->request  = $request;

        $stream->on('data', function($data) {
            $this->handleData($data);
        });

        $stream->on('end', function(Stream $stream) {
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
        $frame = new Frame($msg);
        $frame->maskPayload($frame->generateMaskingKey());

        $this->_stream->write($frame->getContents());
    }

    public function close($code = 1000) {
        $frame = new Frame(pack('n', $code), true, Frame::OP_CLOSE);

        $this->_stream->write($frame->getContents());
        $this->_stream->end();
    }

    private function handleData($data) {
        if (0 === strlen($data)) {
            return;
        }

        if (!$this->_message) {
            $this->_message = new Message;
        }
        if (!$this->_frame) {
            $this->_frame = new Frame;
            $this->_message->addFrame($this->_frame);
        }

        $this->_frame->addBuffer($data);

        if ($this->_frame->isCoalesced()) {
            if (Frame::OP_CLOSE === $this->_frame->getOpcode()) {
                $this->close($this->_frame->getPayload());

                return;
            }

            $overflow = $this->_frame->extractOverflow();
            $this->_frame = null;
        }

        if (!$this->_message->isCoalesced()) {
            if (isset($overflow)) {
                $this->handleData($overflow);
            }

            return;
        }

        $message  = $this->_message->getPayload();

        $this->_frame = $this->_message = null;

        $this->emit('message', [$message, $this]);

        $this->handleData($overflow);
    }
}
