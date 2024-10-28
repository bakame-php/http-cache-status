Cache-Status HTTP Response Header Field
-----

This package contains classes used to parse, validate and manipulate the [Cache-Status HTTP Response Header Field](https://www.rfc-editor.org/rfc/rfc9211.html).

## Usage

```php
use Bakame\Http\CacheStatus\Field;
use Bakame\Http\CacheStatus\ForwardedReason;
use Bakame\Http\CacheStatus\HandledRequestCache;
use Psr\Http\Message\ResponseInterface;

/* @var ResponseInterface $response */
$headerLine = $response->getHeaderLine(Field::NAME);
// 'ReverseProxyCache; hit, ForwardProxyCache; fwd=uri-miss; collapsed; stored';

$statusCode = $response->getStatusCode(); //304
/* @var ResponseInterface $response */
$cacheStatus = Field::fromHttpValue($headerLine, $statusCode);

count($cacheStatus); // returns 2 (the number of HandledRequestCache instances parsed)

$cacheClosestToTheOrigin = $cacheStatus->closestToOrigin(); // the handled request cache closest to the origin server
$cacheClosestToTheClient = $cacheStatus->closestToUser(); // the handled request cache closest to the origin server

$cacheClosestToTheOrigin->hit; // return true
$cacheClosestToTheOrigin->forwardReason; // return null
$cacheClosestToTheClient->hit; // return false
$cacheClosestToTheClient->forwardReason; // return ForwardReason::UriMiss
$cacheClosestToTheClient->forwardStatusCode; // return 304

$newCacheStatus = $cacheStatus->push(
    HandledRequestCache::serverIdentifierAsToken('BrowserCache')
        ->wasForwarded(ForwardedReason::UriMiss);
);
// or the alternative is also acceptable
$newCacheStatus = $cacheStatus->push('BrowserCache; fwd=uri-miss');

$newResponse = $response->withHeader(Field::NAME, $newCacheStatus);
echo $response->getHeaderLine(Field::NAME);
// returns 'ReverseProxyCache;hit, ForwardProxyCache;fwd=uri-miss;collapsed;stored, BrowserCache;fwd=uri-miss'
```

**While we used PSR-7 ResponseInterface, The package parsing and serializing methods can use any HTTP abstraction package or PHP `$_SERVER` array.**

This package depends on [HTTP Structured Fields for PHP](https://github.com/bakame-php/http-structured-fields)
