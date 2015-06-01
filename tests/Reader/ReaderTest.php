<?php
namespace Icicle\Tests\Http\Reader;

use Icicle\Coroutine\Coroutine;
use Icicle\Http\Message\RequestInterface;
use Icicle\Http\Message\ResponseInterface;
use Icicle\Http\Reader\Reader;
use Icicle\Loop;
use Icicle\Promise;
use Icicle\Stream\SeekableStreamInterface;
use Icicle\Stream\Stream;
use Icicle\Tests\Http\TestCase;
use Mockery;
use Symfony\Component\Yaml\Yaml;

class ReaderTest extends TestCase
{
    /**
     * @var \Icicle\Http\Reader\Reader;
     */
    protected $reader;

    public function setUp()
    {
        $this->reader = new Reader();
    }

    /**
     * @return  \Icicle\Stream\ReadableStreamInterface
     */
    protected function createStream()
    {
        return Mockery::mock('Icicle\Stream\ReadableStreamInterface');
    }

    /**
     * @param   string $filename
     *
     * @return  \Icicle\Stream\ReadableStreamInterface
     */
    protected function readMessage($filename)
    {
        $data = file_get_contents(dirname(__DIR__) . '/data/' . $filename);

        $stream = new Stream();
        $stream->end($data);

        return $stream;
    }

    /**
     * @return array
     */
    public function getValidRequests()
    {
        return Yaml::parse(file_get_contents(dirname(__DIR__) . '/data/requests/valid.yml'));
    }

    /**
     * @dataProvider getValidRequests
     * @param   string $filename
     * @param   string $method
     * @param   string $target
     * @param   string $protocolVersion
     * @param   string[][] $headers
     * @param   string|null $body
     */
    public function testReadRequest($filename, $method, $target, $protocolVersion, $headers, $body = null)
    {
        $stream = $this->readMessage($filename);

        $promise = new Coroutine($this->reader->readRequest($stream));

        $promise->done(function (RequestInterface $request) use (
            $method, $target, $protocolVersion, $headers, $body
        ) {
            $this->assertSame($method, $request->getMethod());
            $this->assertSame($target, $request->getRequestTarget());
            $this->assertSame($protocolVersion, $request->getProtocolVersion());
            $this->assertEquals($headers, $request->getHeaders());

            if (null !== $body) { // Check body only if not null.
                $stream = $request->getBody();

                if ($stream instanceof SeekableStreamInterface) {
                    $stream->seek(0);
                    $this->assertSame(strlen($body), $stream->getLength());
                }

                $promise = $stream->read();

                $callback = $this->createCallback(1);
                $callback->method('__invoke')
                    ->with($this->identicalTo($body));

                $promise->done($callback, $this->createCallback(0));

                //Loop\run();
            }
        });

        Loop\run();
    }

    /**
     * @return array
     */
    public function getInvalidRequests()
    {
        return Yaml::parse(file_get_contents(dirname(__DIR__) . '/data/requests/invalid.yml'));
    }

    /**
     * @dataProvider getInvalidRequests
     * @param   string $filename
     * @param   string $exceptionClass
     */
    public function testReadInvalidRequest($filename, $exceptionClass)
    {
        $stream = $this->readMessage($filename);

        $promise = new Coroutine($this->reader->readRequest($stream));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf($exceptionClass));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @return array
     */
    public function getValidResponses()
    {
        return Yaml::parse(file_get_contents(dirname(__DIR__) . '/data/responses/valid.yml'));
    }

    /**
     * @dataProvider getValidResponses
     * @param   string $filename
     * @param   int $code
     * @param   string $reason
     * @param   string $protocolVersion
     * @param   string[][] $headers
     * @param   string|null $body
     */
    public function testReadResponse($filename, $code, $reason, $protocolVersion, $headers, $body = null)
    {
        $stream = $this->readMessage($filename);

        $promise = new Coroutine($this->reader->readResponse($stream));

        $promise->done(function (ResponseInterface $response) use (
            $code, $reason, $protocolVersion, $headers, $body
        ) {
            $this->assertSame($code, $response->getStatusCode());
            $this->assertSame($reason, $response->getReasonPhrase());
            $this->assertSame($protocolVersion, $response->getProtocolVersion());
            $this->assertEquals($headers, $response->getHeaders());

            if (null !== $body) { // Check body only if not null.
                $stream = $response->getBody();

                if ($stream instanceof SeekableStreamInterface) {
                    $stream->seek(0);
                    $this->assertSame(strlen($body), $stream->getLength());
                }

                $promise = $stream->read();

                $callback = $this->createCallback(1);
                $callback->method('__invoke')
                    ->with($this->identicalTo($body));

                $promise->done($callback, $this->createCallback(0));
            }
        });

        Loop\run();
    }

    /**
     * @return array
     */
    public function getInvalidResponses()
    {
        return Yaml::parse(file_get_contents(dirname(__DIR__) . '/data/responses/invalid.yml'));
    }

    /**
     * @dataProvider getInvalidResponses
     * @param   string $filename
     * @param   string $exceptionClass
     */
    public function testReadInvalidResponse($filename, $exceptionClass)
    {
        $stream = $this->readMessage($filename);

        $promise = new Coroutine($this->reader->readResponse($stream));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf($exceptionClass));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadRequest
     */
    public function testReadRequestMaxSizeExceeded()
    {
        $stream = $this->createStream();
        $maxSize = 1;

        $stream->shouldReceive('read')
            ->andReturn(Promise\resolve("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n"));

        $promise = new Coroutine($this->reader->readRequest($stream, $maxSize));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf('Icicle\Http\Exception\MessageHeaderSizeException'));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadResponse
     */
    public function testReadResponseMaxSizeExceeded()
    {
        $stream = $this->createStream();
        $maxSize = 1;

        $stream->shouldReceive('read')
            ->andReturn(Promise\resolve("HTTP/1.1 200 OK\r\n\r\n"));

        $promise = new Coroutine($this->reader->readResponse($stream, $maxSize));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf('Icicle\Http\Exception\MessageHeaderSizeException'));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }
}