<?php

// Required Autoloader (use __DIR__ so this resolves regardless of CWD)
require_once(__DIR__ . '/../vendor/autoload.php');

// Set CWD to project root so all relative paths (media/full/, db/, etc.) resolve correctly.
// Without this, Apache may set CWD to api/ which breaks MediaCollection paths.
chdir(__DIR__ . '/..');

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Routes\Internal\DuplicatesController;
use Routes\Internal\MediaController;
use Routes\Internal\TagController;
use Routes\Internal\UploadController;
use Gallery\Core\Configuration;
use Gallery\Core\Logger;
use Gallery\Core\RateLimiter;

// Create Container using PHP-DI
$container = new Container();

// Register Container
AppFactory::setContainer($container);

// Set up the App and Log
$app = AppFactory::create();

// Set Base Path
$app->setBasePath("/api");

// Setup Middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Setup Error Middleware (disable detailed errors in production)
$error_middleware = $app->addErrorMiddleware(false, true, true);

// ============================================================
// Reusable Auth Token Verification
// ============================================================

/**
 * Verify a Bearer token from the Authorization header.
 * Returns true if valid, false otherwise.
 */
function verifyAuthToken(string $authHeader): bool
{
    $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';

    if (empty($token)) {
        return false;
    }

    $db = \Gallery\Core\DatabaseConnection::getInstance();
    $stmt = $db->prepare('SELECT 1 FROM auth_tokens WHERE token = :token AND created_at >= :min_time');
    $stmt->execute([':token' => $token, ':min_time' => time() - 86400]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Create an unauthorized JSON response.
 */
function unauthorizedResponse(): ResponseInterface
{
    $response = new \Slim\Psr7\Response();
    $response->getBody()->write(json_encode([
        'error' => 'Unauthorized',
        'message' => 'Authentication is required. Please log in and try again.',
    ]));
    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
}

// ============================================================
// Auth Middleware for State-Changing Operations
// ============================================================
$authMiddleware = function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $method = strtoupper($request->getMethod());
    $path = $request->getUri()->getPath();

    // Skip auth for login endpoint and OPTIONS preflight
    if ($method === 'OPTIONS' || str_ends_with($path, '/auth/login') || str_ends_with($path, '/auth/login/')) {
        return $handler->handle($request);
    }

    // Only require auth for state-changing methods
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        // Allow unauthenticated tag add/remove on media items
        $path = $request->getUri()->getPath();
        $publicTagPaths = ['/tags/media/add', '/tags/media/remove'];
        $isPublicTagOp = false;
        foreach ($publicTagPaths as $p) {
            if (str_contains($path, $p)) {
                $isPublicTagOp = true;
                break;
            }
        }

        if (!$isPublicTagOp && !verifyAuthToken($request->getHeaderLine('Authorization'))) {
            return unauthorizedResponse();
        }
    }

    return $handler->handle($request);
};

// Rate Limiting Middleware
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $rateLimiter = new RateLimiter(120, 60);
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
    $result = $rateLimiter->check($ip);

    if (!$result['allowed']) {
        Logger::getInstance()->warning('Rate limit exceeded', ['ip' => $ip, 'retry_after' => $result['retry_after']]);
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'error' => 'RateLimitExceeded',
            'message' => 'Too many requests. Please wait a moment and try again.',
            'retry_after' => $result['retry_after'],
        ]));
        return $response
            ->withStatus(429)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $result['retry_after'])
            ->withHeader('X-RateLimit-Remaining', '0');
    }

    $response = $handler->handle($request);
    return $response->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
});

// CSRF Origin Check Middleware
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $method = strtoupper($request->getMethod());

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $origin = $request->getHeaderLine('Origin');
        $referer = $request->getHeaderLine('Referer');
        $allowedOrigins = Configuration::getAllowedOrigins();

        if (!empty($origin) && !in_array($origin, $allowedOrigins, true)) {
            Logger::getInstance()->warning('CSRF origin rejected', ['origin' => $origin]);
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'error' => 'ForbiddenOrigin',
                'message' => 'The request origin is not allowed.',
            ], JSON_THROW_ON_ERROR));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        if (empty($origin) && !empty($referer)) {
            $refererOrigin = parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST);
            $port = parse_url($referer, PHP_URL_PORT);
            if ($port) {
                $refererOrigin .= ':' . $port;
            }
            if (!in_array($refererOrigin, $allowedOrigins, true)) {
                Logger::getInstance()->warning('CSRF referer rejected', ['referer' => $referer]);
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode([
                    'error' => 'ForbiddenOrigin',
                    'message' => 'The request origin is not allowed.',
                ], JSON_THROW_ON_ERROR));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
        }
    }

    return $handler->handle($request);
});

// CORS headers
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    if (strtoupper($request->getMethod()) === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
    } else {
        $response = $handler->handle($request);
    }

    $origin = $request->getHeaderLine('Origin');
    $allowed_origins = Configuration::getAllowedOrigins();

    $response = $response
        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff');

    if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
        $response = $response
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    return $response;
});

// CORS Preflight OPTIONS Handler
$app->options('/{routes:.+}', function (ServerRequestInterface $request, ResponseInterface $response) {
    return $response->withStatus(204);
});

// ============================================================
// Media Controllers (unified images + videos)
// ============================================================
$app->group('/media', function (RouteCollectorProxy $group) {
    $group->get('/random[/]', MediaController::class . ':getRandomItem');
    $group->post('/by-ids[/]', MediaController::class . ':getItemsByIds');
    $group->get('/untagged/{page}[/[{items_per_page}[/]]]', MediaController::class . ':getUntaggedItems');
    $group->get('/page/{page}[/[{items_per_page}[/]]]', MediaController::class . ':getItemsForPage');
    $group->get('/with-tags/{tag_list}/{page}[/[{items_per_page}[/]]]', MediaController::class . ':getItemsWithTags');
    $group->get('/total[/]', MediaController::class . ':getTotal');
    $group->delete('/{media_id}[/]', MediaController::class . ':deleteItem');
    $group->get('/[{media_id}[/]]', MediaController::class . ':getItem');
});

// ============================================================
// Tag Controllers
// ============================================================
$app->group('/tags', function (RouteCollectorProxy $group) {
    $group->get('/all[/]', TagController::class . ':getAllTags');
    $group->get('/display[/]', TagController::class . ':getTagListForDisplay');
    $group->get('/tag/{tag_id}[/]', TagController::class . ':getTag');
    $group->get('/for/media/{media_id}[/]', TagController::class . ':getTagsForMedia');
    // Protected: state-changing tag operations
    $group->post('/add[/]', TagController::class . ':addTag');
    $group->put('/edit/{tag_id}[/]', TagController::class . ':editTag');
    $group->patch('/media/add[/]', TagController::class . ':addTagsToMedia');
    $group->patch('/media/remove[/]', TagController::class . ':removeTagFromMedia');
    $group->post('/migrate[/]', TagController::class . ':migrateTag');
    $group->delete('/delete[/]', TagController::class . ':deleteTag');
    // Tag implications
    $group->get('/implications[/]', TagController::class . ':getImplications');
    $group->post('/implications/add[/]', TagController::class . ':addImplication');
    $group->delete('/implications/remove[/]', TagController::class . ':removeImplication');
})->add($authMiddleware);

// ============================================================
// Authentication Endpoint
// ============================================================
$app->post('/auth/login[/]', function (ServerRequestInterface $request, ResponseInterface $response) {
    $params = json_decode((string)$request->getBody(), true) ?? [];
    $password = $params['password'] ?? '';

    if ($password === Configuration::getAdminPassword()) {
        $token = bin2hex(random_bytes(32));
        $db = \Gallery\Core\DatabaseConnection::getInstance();

        $db->exec('DELETE FROM auth_tokens WHERE created_at < ' . (time() - 86400));

        $stmt = $db->prepare('INSERT INTO auth_tokens (token, created_at) VALUES (:token, :time)');
        $stmt->execute([':token' => $token, ':time' => time()]);

        Logger::getInstance()->info('Admin login successful', ['ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown']);
        $response->getBody()->write(json_encode(['token' => $token]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    Logger::getInstance()->warning('Admin login failed', ['ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown']);
    $response->getBody()->write(json_encode([
        'error' => 'InvalidPassword',
        'message' => 'The password is incorrect.',
    ]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
});

// ============================================================
// Upload Controllers (protected by auth middleware)
// ============================================================
$app->group('/upload', function (RouteCollectorProxy $group) {
    $group->post('/media[/]', UploadController::class . ':uploadMedia');
})->add($authMiddleware);

// ============================================================
// Duplicates Controllers (protected by auth middleware)
// ============================================================
$app->group('/duplicates', function (RouteCollectorProxy $group) {
    $group->get('/report[/]', DuplicatesController::class . ':getLatestReport');
    $group->post('/scan[/]', DuplicatesController::class . ':runScan');
    $group->post('/dismiss[/]', DuplicatesController::class . ':dismissPair');
    $group->delete('/media[/]', DuplicatesController::class . ':deleteMedia');
})->add($authMiddleware);

// Run the app
$app->run();
