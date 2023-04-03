<?php

namespace BeyondCode\LaravelWebSockets\HttpApi\Controllers;

use Exception;
use Pusher\Pusher;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use GuzzleHttp\Psr7\Response;
use Ratchet\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Support\Collection;
use React\Promise\PromiseInterface;
use Ratchet\Http\HttpServerInterface;
use Psr\Http\Message\RequestInterface;
use BeyondCode\LaravelWebSockets\Apps\App;
use BeyondCode\LaravelWebSockets\QueryParameters;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;

abstract class Controller implements HttpServerInterface
{
    /** @var string */
    protected $requestBuffer = '';

    /** @var RequestInterface */
    protected $request;

    /** @var int */
    protected $contentLength;

    /** @var ChannelManager */
    protected $channelManager;

    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    public function onOpen(ConnectionInterface $connection, RequestInterface $request = null)
    {
        $this->request = $request;

        $this->contentLength = $this->findContentLength($request->getHeaders());

        $this->requestBuffer = (string) $request->getBody();

        if (! $this->verifyContentLength()) {
            return;
        }

        $this->handleRequest($connection);
    }

    protected function findContentLength(array $headers): int
    {
        return Collection::make($headers)->first(function ($values, $header) {
            return strtolower($header) === 'content-length';
        })[0] ?? 0;
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->requestBuffer .= $msg;

        if (! $this->verifyContentLength()) {
            return;
        }

        $this->handleRequest($from);
    }

    protected function verifyContentLength()
    {
        return strlen($this->requestBuffer) === $this->contentLength;
    }

    protected function handleRequest(ConnectionInterface $connection)
    {
        $serverRequest = (new ServerRequest(
            $this->request->getMethod(),
            $this->request->getUri(),
            $this->request->getHeaders(),
            $this->requestBuffer,
            $this->request->getProtocolVersion()
        ))->withQueryParams(QueryParameters::create($this->request)->all());

        $laravelRequest = Request::createFromBase((new HttpFoundationFactory)->createRequest($serverRequest));

        $this
            ->ensureValidAppId($laravelRequest->appId)
            ->ensureValidSignature($laravelRequest);

        // Invoke the controller action
        $response = $this($laravelRequest);

        // Allow for async IO in the controller action
        if ($response instanceof PromiseInterface) {
            $response->then(function ($response) use ($connection) {
                $this->sendAndClose($connection, $response);
            });

            return;
        }

        $this->sendAndClose($connection, $response);
    }

    protected function sendAndClose(ConnectionInterface $connection, $response)
    {
        $connection->send(JsonResponse::create($response));
        $connection->close();
    }

    public function onClose(ConnectionInterface $connection)
    {
    }

    public function onError(ConnectionInterface $connection, Exception $exception)
    {
        if (! $exception instanceof HttpException) {
            return;
        }

        $response = new Response($exception->getStatusCode(), [
            'Content-Type' => 'application/json',
        ], json_encode([
            'error' => $exception->getMessage(),
        ]));

        $connection->send(\GuzzleHttp\Psr7\str($response));

        $connection->close();
    }

    public function ensureValidAppId(string $appId)
    {
        if (! App::findById($appId)) {
            throw new HttpException(401, "Unknown app id `{$appId}` provided.");
        }

        return $this;
    }

    protected function ensureValidSignature(Request $request)
    {
        /*
         * The `auth_signature` & `body_md5` parameters are not included when calculating the `auth_signature` value.
         *
         * The `appId`, `appKey` & `channelName` parameters are actually route parameters and are never supplied by the client.
         */
        $params = Arr::except($request->query(), ['auth_signature', 'body_md5', 'appId', 'appKey', 'channelName']);

        if ($request->getContent() !== '') {
            $params['body_md5'] = md5($request->getContent());
        }

        ksort($params);

        $signature = "{$request->getMethod()}\n/{$request->path()}\n".Pusher::array_implode('=', '&', $params);

        $authSignature = hash_hmac('sha256', $signature, App::findById($request->get('appId'))->secret);

        if ($authSignature !== $request->get('auth_signature')) {
            throw new HttpException(401, 'Invalid auth signature provided.');
        }

        return $this;
    }

    abstract public function __invoke(Request $request);
}
