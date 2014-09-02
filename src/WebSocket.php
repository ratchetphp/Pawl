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

        $ping = false;
        if (Frame::OP_PING === $this->_frame->getOpcode()) {
            $frame = new Frame($this->_frame->getPayload(), true, Frame::OP_PONG);
            $frame->maskPayload($frame->generateMaskingKey());
            $this->_stream->write($frame->getContents());
            $ping = true;
        }

        if ($this->_frame->isCoalesced()) {
            $opcode = $this->_frame->getOpcode();

            if ($opcode > 2) {
                if ($this->_frame->getPayloadLength() > 125 || !$this->_frame->isFinal()) {
                    $this->close(Frame::CLOSE_PROTOCOL);
                    return;
                }

                switch ($opcode) {
                    case Frame::OP_CLOSE:
                        $this->close($this->_frame->getPayload());

                        return;
                    case Frame::OP_PING:
                        $this->send(new Frame($this->_frame->getPayload(), true, Frame::OP_PONG));
                        break;
                    case Frame::OP_PONG:
                        $this->emit('pong', [$this->_frame, $this]);
                        break;
                    default:
                        $this->close($this->_frame->getPayload());
                        return;
                }
            }

            $overflow = $this->_frame->extractOverflow();
            $this->_frame = null;

            // if this is a control frame, then we aren't going to be coalescing
            // any message, just handle overflowing stuff now and return
            if ($opcode > 2) {
                $this->handleData($overflow);
                return;
            }
        }

        if (!$this->_message->isCoalesced()) {
            if (isset($overflow)) {
                $this->handleData($overflow);
            }

            return;
        }

        $message  = $this->_message->getPayload();

        $this->_frame = $this->_message = null;

        if (!$ping)
            $this->emit('message', [$message, $this]);


        $this->handleData($overflow);
    }
}
