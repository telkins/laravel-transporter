<?php

declare(strict_types=1);

namespace JustSteveKing\Transporter;

use JustSteveKing\StatusCode\Http;
use OutOfBoundsException;
use BadMethodCallException;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Factory as HttpFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use RuntimeException;

abstract class Request
{
    use Macroable {
        __call as macroCall;
    }

    protected bool $useFake = false;

    protected PendingRequest $request;

    protected Response $fakeResponse;

    protected string $method;
    protected string $path;
    protected string $baseUrl;

    protected array $query = [];
    protected array $data = [];
    protected array $fakeData = [];
    protected int $status;

    public static function build(...$args): static
    {
        return app(static::class, $args);
    }

    public static function fake(int $status = Http::OK): static
    {
        $request = static::build();

        $request->useFake = true;
        $request->status = $status;

        return $request;
    }

    public function __construct(HttpFactory $http)
    {
        $this->request = $http->baseUrl(
            url: config('transporter.base_uri') ?? $this->baseUrl ?? '',
        );

        $this->withRequest(
            request: $this->request,
        );
    }

    public function withData(array $data): static
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function withFakeData(array $data): static
    {
        $this->fakeData = array_merge($this->fakeData, $data);

        return $this;
    }

    public function withQuery(array $query): static
    {
        $this->query = array_merge($this->query, $query);

        return $this;
    }

    public function getBaseUrl(): string
    {
        if (isset($this->baseUrl)) {
            return $this->baseUrl;
        }

        if (! is_null(config('transporter.base_uri'))) {
            return config('transporter.base_uri');
        }

        throw new RuntimeException(
            message: "Neither a baseUrl or a config base_uri has been set for this request.",
        );
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        $this->request->baseUrl($baseUrl);

        return $this;
    }

    protected function fakeResponse(): Psr7Response
    {
        return new Psr7Response(
            status: $this->status,
            body:   json_encode($this->fakeData),
        );
    }

    public function send(): Response
    {
        if ($this->useFake) {
            return new Response(
                response: $this->fakeResponse(),
            );
        }

        $url = (string) Str::of($this->path())
            ->when(
                !empty($this->query),
                fn (Stringable $path): Stringable => $path->append('?', http_build_query($this->query))
            );

        return match (mb_strtoupper($this->method)) {
            "GET" => $this->request->get($this->path(), $this->query),
            "POST" => $this->request->post($url, $this->data),
            "PUT" => $this->request->put($url, $this->data),
            "PATCH" => $this->request->patch($url, $this->data),
            "DELETE" => $this->request->delete($url, $this->data),
            "HEAD" => $this->request->head($this->path(), $this->query),
            default => throw new OutOfBoundsException()
        };
    }

    protected function withRequest(PendingRequest $request): void
    {
        // do something with the initialized request
    }

    protected function path(): string
    {
        return $this->path;
    }

    public function getRequest(): PendingRequest
    {
        return $this->request;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function __call(string $method, array $parameters): static
    {
        if (method_exists($this->request, $method)) {
            call_user_func_array([$this->request, $method], $parameters);

            return $this;
        }

        throw new BadMethodCallException();
    }
}
