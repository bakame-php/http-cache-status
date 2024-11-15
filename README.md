Cache-Status HTTP Response Header Field
-----

This package contains classes used to parse, validate and manipulate the [Cache-Status HTTP Response Header Field](https://www.rfc-editor.org/rfc/rfc9211.html).

## Usage

Unless explicitly stated, all the classes discribed hereafter are immutable.

### Parsing

The package can parse the Header from ab HTTP Request or Response using the `Field` class as follows:

```php
use Bakame\Http\CacheStatus\Field;
use Bakame\Http\CacheStatus\ForwardedReason;
use Bakame\Http\CacheStatus\HandledRequestCache;

/* @var Psr\Http\Message\ResponseInterface $response */
$headerLine = $response->getHeaderLine(Field::NAME);
// 'ReverseProxyCache; hit, ForwardProxyCache; fwd=uri-miss; collapsed; stored';
$statusCode = $response->getStatusCode(); //304

$cacheStatus = Field::fromHttpValue($headerLine, $statusCode);
```

### Field Container

The `Field` class is a container whose members are handled request cache information as they are added by the various
server. The class implements PHP's `IteratorAggregate`, `ArrayAccess`, `Countable` and `Stringable` interface.

```php
echo $cacheStatus;    // returns 'ReverseProxyCache; hit, ForwardProxyCache; fwd=uri-miss; collapsed; stored';
echo $cacheStatus[1]; // returns 'ForwardProxyCache; fwd=uri-miss; collapsed; stored';
count($cacheStatus);  // returns 2
```

As per the RFC the `closestToOrigin` and `closestToUser` methods give you access to the caches closest to the 
origin server and the one closest to the client (user). 

```php
$cacheClosestToTheOrigin = $cacheStatus->closestToOrigin(); // the handled request cache closest to the origin server
$cacheClosestToTheClient = $cacheStatus->closestToUser(); // the handled request cache closest to the user
```

### The Handled Request Cache object

Both methods return `null` if the cache does not exist or a `HandledRequestCache` instance.

```php
$cacheClosestToTheOrigin = $cacheStatus->closestToOrigin(); // the handled request cache closest to the origin server
$cacheClosestToTheClient = $cacheStatus->closestToUser(); // the handled request cache closest to the user
$cacheClosestToTheOrigin->hit; // return true
$cacheClosestToTheOrigin->forward; // return null
$cacheClosestToTheClient->hit; // return false
$cacheClosestToTheClient->forward->reason; // return ForwardReason::UriMiss
$cacheClosestToTheClient->forward->statusCode; // return 304
```

A `HandledRequestCache` instance contains information about the cache and how it handled the current message.
In particular, in compliance with the RFC, if the `forward` property is present you will get extra information
regarding the reason why the cache was forwarded.

```php

$cacheClosestToTheClient->forward->reason; // return ForwardReason::UriMiss
$cacheClosestToTheClient->forward->statusCode; // return 304
if ($cacheClosestToTheClient->forwardReason->isOneOf(ForwardedReason::Miss, ForwardedReason::UriMiss)) {
    //you can do something useful here
}
```

The class lists all the available reason via the `ForwardedReason` Enum.

Last but not least you can push more `HandledRequestCache` instances to the `Field` container using the `push` method.
The method supports pushing `HandledRequestCache` instances as well as HTTP text representation of the handled request
cache.

```php
$newCacheStatus = $cacheStatus->push(
    HandledRequestCache::serverIdentifierAsToken('BrowserCache')
        ->wasForwarded(Forward::fromReason(ForwardedReason::UriMiss))
);
// or you can use push an HTTP header
$newCacheStatus = $cacheStatus->push('BrowserCache; fwd=uri-miss');
```

### Serializing the message field

Once you have created or updated you `Field` instance you just need to add it to your response header using the
`toHttpValue` method or the `__toString()` to generate the correct HTTP text representation to insert in your 
message header.

```php
// or you can use push an HTTP header
$newCacheStatus = $cacheStatus->push('BrowserCache; fwd=uri-miss');

$newResponse = $response->withHeader(Field::NAME, $newCacheStatus);
echo $response->getHeaderLine(Field::NAME);
```

**While we used PSR-7 ResponseInterface, The package parsing and serializing methods can use any HTTP abstraction package or PHP `$_SERVER` array.**

```php
$cacheStatus = Field::fromSapiServer($_SERVER, 'HTTP_CACHE_STATUS');
$newCacheStatus = $cacheStatus->push('BrowserCache; fwd=uri-miss');

header(Field::NAME.': '.$newCacheStatus);
```

In this last example we use PHP native function to parse and add the correct header to the HTTP response.

## Structured Field

This Header field is compliant with the HTTP Structured Field RFC. As such we take advantage of that fact by using 
the [HTTP Structured Fields for PHP](https://github.com/bakame-php/http-structured-fields) v2.0,  which remove all the
boilerplate needed for such header to be parsable and manipulable in PHP while staying compliant with all the different
RFC.
