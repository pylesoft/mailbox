<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ResponseBudget;
use RuntimeException;

/** @internal */
final class BoundedGraphTransport
{
    private CurlHandler $curl;

    public function __construct()
    {
        $this->curl = new CurlHandler;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function options(#[\SensitiveParameter] array $options, int $maxBytes, ?ResponseBudget $budget, bool $mimeHeadersOnly = false, bool $allowTruncated = false, ?int $maxSuccessBytes = null): array
    {
        $body = Utils::streamFor(Utils::tryFopen('php://temp/maxmemory:32768', 'w+b'));
        $extra = (int) $allowTruncated;
        $remaining = min($maxBytes, PHP_INT_MAX - $extra) + $extra;
        $written = 0;
        $stop = false;
        $overflow = false;
        $tail = '';
        $rejectHttpError = ($options['http_errors'] ?? false) === true;

        $sink = FnStream::decorate($body, [
            'write' => static function (#[\SensitiveParameter] string $chunk) use ($body, $mimeHeadersOnly, $allowTruncated, &$remaining, &$written, &$stop, &$overflow, &$tail): int {
                $length = strlen($chunk);
                if ($mimeHeadersOnly) {
                    $end = strpos($tail.$chunk, "\r\n\r\n");
                    if ($end !== false) {
                        $chunk = substr($chunk, 0, $end + 4 - strlen($tail));
                        $stop = true;
                    } else {
                        $tail = substr($tail.$chunk, -3);
                    }
                }

                if (strlen($chunk) > $remaining) {
                    $chunk = substr($chunk, 0, $remaining);
                    $overflow = ! $allowTruncated;
                    $stop = $allowTruncated;
                }

                $count = $body->write($chunk);
                $remaining -= $count;
                $written += $count;

                return $stop || $overflow ? 0 : min($length, $count);
            },
        ]);

        $options['on_headers'] = static function (#[\SensitiveParameter] ResponseInterface $response) use ($rejectHttpError, $mimeHeadersOnly, $maxSuccessBytes, $extra, &$remaining, &$stop): void {
            $status = $response->getStatusCode();
            if ($status < 200) {
                return;
            }

            if (($rejectHttpError && $status >= 400) || ($mimeHeadersOnly && $status !== 200)
                || ! in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
                $stop = true;
                throw new RuntimeException('The Graph response body is not needed for this status.');
            }
            if ($maxSuccessBytes !== null && $status < 300) {
                $remaining = min($maxSuccessBytes, PHP_INT_MAX - $extra) + $extra;
            }
        };

        $options['handler'] = HandlerStack::create(function (#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options) use ($sink, $budget, $allowTruncated, &$stop, &$overflow, &$written): PromiseInterface {
            return ($this->curl)($request, $options)->otherwise(static function (#[\SensitiveParameter] mixed $failure) use ($sink, $budget, $allowTruncated, &$stop, &$overflow, &$written): ResponseInterface|PromiseInterface {
                if ($stop && $failure instanceof RequestException && $failure->getResponse() instanceof ResponseInterface) {
                    $sink->rewind();

                    return $failure->getResponse();
                }

                $sink->close();
                if (! $allowTruncated) {
                    $budget?->consume($written);
                }
                if ($overflow) {
                    throw new ResponseLimitExceededException('The provider response exceeded the remaining byte budget.');
                }

                return Create::rejectionFor($failure);
            });
        });

        return [...$options, 'stream' => false, 'sink' => $sink];
    }
}
