<?php

declare(strict_types=1);

namespace Rarst\ReleaseBelt\Provider;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\Finder\Finder;

/**
 * Implements HTTP authentication as a PSR-15 middleware.
 *
 * When users are configured, this middleware guards all routes:
 *  - /login              always triggers the Basic Auth browser dialog; on
 *                        success it redirects back to the index page.
 *  - / and /packages.json  accessible without credentials; unauthenticated
 *                        requests see only public-vendor packages.
 *  - /{public-vendor}/*  accessible without credentials.
 *  - everything else     requires valid credentials (returns 401 otherwise).
 *
 * When no users are configured the middleware is not added to the app and
 * every route is fully public.
 *
 * @psalm-suppress MissingConstructor
 */
class AuthenticationProvider implements MiddlewareInterface
{
    protected ContainerInterface $container;

    /** @var array<string, string> username → bcrypt hash */
    protected array $userHashes = [];

    /** @var string[] URL-prefixed public paths, e.g. ['/acme', '/my-plugins'] */
    protected array $publicPaths = [];

    /**
     * Does necessary registrations on the app instance.
     */
    public function boot(App $app): void
    {
        /** @var ContainerInterface $container */
        $container       = $app->getContainer();
        $this->container = $container;

        $userHashes = $this->getUserHashes();

        if (empty($userHashes)) {
            return;
        }

        $this->userHashes  = $userHashes;
        $this->publicPaths = $this->getPublicPaths();

        $app->add($this);
    }

    /**
     * PSR-15 process: validates credentials and enforces access rules.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path        = $request->getUri()->getPath();
        $credentials = $this->extractCredentials($request);

        // /login exists solely to trigger the browser's credential dialog; on
        // success it redirects home, on failure it re-challenges.
        if ($path === '/login') {
            if ($credentials !== null) {
                [$user, $password] = $credentials;
                if ($this->validateCredentials($user, $password)) {
                    return $this->redirectResponse('/');
                }
            }

            return $this->unauthorizedResponse();
        }

        $isAuthenticated = false;

        if ($credentials !== null) {
            [$user, $password] = $credentials;

            if ($this->validateCredentials($user, $password)) {
                $isAuthenticated = true;

                // Apply per-user Finder filters and tag the request.
                $permissions = $this->getPermissions($this->container->get('users'), $user);
                $this->applyPermissions($this->container->get(Finder::class), $permissions);

                // When the user has an explicit allow list, also include public
                // packages so they remain visible/downloadable via packages.json and
                // the web interface even for restricted users.
                if (! empty($permissions['allow'])) {
                    $this->applyPublicFilter($this->container->get(Finder::class));
                }

                $request = $request->withAttribute('username', $user);
            } elseif ($this->requiresAuthentication($path)) {
                // Invalid credentials on a protected path → 401.
                return $this->unauthorizedResponse();
            }
            // Invalid credentials on a public path: fall through as unauthenticated
            // so that truly-public packages remain accessible even when a Composer
            // client replays stale credentials stored from a previous session.
        } elseif ($this->requiresAuthentication($path)) {
            return $this->unauthorizedResponse();
        }

        // Unauthenticated (or degraded-to-unauthenticated) browsing: restrict to
        // public packages only.
        if (! $isAuthenticated && in_array($path, ['/', '/packages.json'], true)) {
            $this->applyPublicFilter($this->container->get(Finder::class));
        }

        return $handler->handle($request);
    }

    /**
     * Returns true when the given path must not be served without credentials.
     */
    private function requiresAuthentication(string $path): bool
    {
        // /login must always challenge the browser.
        if ($path === '/login') {
            return true;
        }

        // No public vendors → original behaviour: every route is protected.
        if (empty($this->publicPaths)) {
            return true;
        }

        // Browsing pages are always reachable (with a public-only package view).
        if (in_array($path, ['/', '/packages.json'], true)) {
            return false;
        }

        return ! $this->isPublicVendorPath($path);
    }

    /**
     * Returns true when $path falls under one of the configured public vendors.
     */
    private function isPublicVendorPath(string $path): bool
    {
        foreach ($this->publicPaths as $prefix) {
            if (strpos($path, $prefix . '/') === 0 || $path === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds Finder path filters so that only public-vendor packages are visible.
     */
    private function applyPublicFilter(Finder $finder): void
    {
        foreach ($this->publicPaths as $prefix) {
            $finder->path(ltrim($prefix, '/'));
        }
    }

    /**
     * Extracts [username, password] from an Authorization: Basic … header.
     *
     * Returns null when the header is absent or malformed.
     *
     * @return string[]|null
     */
    private function extractCredentials(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || ! preg_match('/Basic\s+(.*)$/i', $header, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1]);

        if (strpos($decoded, ':') === false) {
            return null;
        }

        return explode(':', $decoded, 2);
    }

    /**
     * Validates a username / plain-text password pair against stored bcrypt hashes.
     */
    private function validateCredentials(string $user, string $password): bool
    {
        return isset($this->userHashes[$user])
            && password_verify($password, $this->userHashes[$user]);
    }

    /**
     * Builds a 401 response that triggers the browser's Basic Auth dialog.
     */
    private function unauthorizedResponse(): ResponseInterface
    {
        return (new ResponseFactory())
            ->createResponse(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="Protected"');
    }

    /**
     * Builds a 302 redirect response.
     */
    private function redirectResponse(string $location): ResponseInterface
    {
        return (new ResponseFactory())
            ->createResponse(302)
            ->withHeader('Location', $location);
    }

    /**
     * Retrieves URL path patterns for publicly accessible packages.
     *
     * Each vendor name from the `public` config is converted to a URL path
     * prefix (e.g. `acme` → `/acme`).
     */
    protected function getPublicPaths(): array
    {
        /** @var string[] $publicPaths */
        $publicPaths = $this->container->has('public') ? $this->container->get('public') : [];

        return array_map(
            fn(string $path): string => '/' . ltrim($path, '/'),
            $publicPaths
        );
    }

    /**
     * Retrieves a set of user names with password hashes.
     */
    protected function getUserHashes(): array
    {
        /** @var string[] $users */
        $users = $this->container->has('http.users') ? $this->container->get('http.users') : [];

        if ($users) {
            trigger_error('`http.users` option is deprecated in favor of `users`.', E_USER_DEPRECATED);
        }

        foreach ($this->container->get('users') as $login => $data) {
            $users[$login] = $data['hash'] ?? '';
        }

        return array_filter($users);
    }

    /**
     * Retrieves package access permissions for a specific user.
     *
     * @param array[] $users
     */
    protected function getPermissions(array $users, string $user): array
    {
        return [
            'allow'    => $users[$user]['allow'] ?? [],
            'disallow' => $users[$user]['disallow'] ?? [],
        ];
    }

    /**
     * Applies access permissions on a Finder instance for package lookup.
     */
    protected function applyPermissions(Finder $finder, array $permissions): Finder
    {
        foreach ($permissions['allow'] as $path) {
            $finder->path((string)$path);
        }

        foreach ($permissions['disallow'] as $path) {
            $finder->notPath((string)$path);
        }

        return $finder;
    }
}
