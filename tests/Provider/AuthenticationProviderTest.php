<?php

declare(strict_types=1);

namespace Rarst\ReleaseBelt\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rarst\ReleaseBelt\Provider\AuthenticationProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Finder\Finder;

class AuthenticationProviderTest extends TestCase
{
    /** @var AuthenticationProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->provider = new class extends AuthenticationProvider {
            public function setContainer(ContainerInterface $container): void
            {
                $this->container = $container;
            }

            public function setUserHashes(array $hashes): void
            {
                /** @phpstan-ignore-next-line */
                $this->userHashes = $hashes;
            }

            public function setPublicPaths(array $paths): void
            {
                /** @phpstan-ignore-next-line */
                $this->publicPaths = $paths;
            }

            public function exposedGetPublicPaths(): array
            {
                return $this->getPublicPaths();
            }
        };
    }

    // -------------------------------------------------------------------------
    // getPublicPaths()
    // -------------------------------------------------------------------------

    /**
     * Tests that getPublicPaths returns an empty array when no public paths are configured.
     */
    public function testGetPublicPathsReturnsEmptyArrayWhenNotConfigured(): void
    {
        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('has')->with('public')->willReturn(false);

        $this->provider->setContainer($containerMock);

        $this->assertSame([], $this->provider->exposedGetPublicPaths());
    }

    /**
     * Tests that getPublicPaths converts vendor names to URL path prefixes.
     */
    public function testGetPublicPathsConvertsVendorNamesToUrlPaths(): void
    {
        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('has')->with('public')->willReturn(true);
        $containerMock->method('get')->with('public')->willReturn(['acme', 'vendor2']);

        $this->provider->setContainer($containerMock);

        $this->assertSame(['/acme', '/vendor2'], $this->provider->exposedGetPublicPaths());
    }

    /**
     * Tests that getPublicPaths strips leading slashes from configured paths.
     */
    public function testGetPublicPathsStripsLeadingSlashes(): void
    {
        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('has')->with('public')->willReturn(true);
        $containerMock->method('get')->with('public')->willReturn(['/acme', '//vendor2']);

        $this->provider->setContainer($containerMock);

        $this->assertSame(['/acme', '/vendor2'], $this->provider->exposedGetPublicPaths());
    }

    // -------------------------------------------------------------------------
    // process() – access-control rules
    // -------------------------------------------------------------------------

    /**
     * Unauthenticated request to / is allowed when public paths are configured.
     */
    public function testProcessAllowsUnauthenticatedBrowsingWhenPublicPathsSet(): void
    {
        $finder = (new Finder())->files();
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);
        $this->provider->setContainer($this->makeContainerWithFinder($finder));

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    /**
     * Unauthenticated request to /packages.json is allowed when public paths are configured.
     */
    public function testProcessAllowsUnauthenticatedPackagesJsonWhenPublicPathsSet(): void
    {
        $finder = (new Finder())->files();
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);
        $this->provider->setContainer($this->makeContainerWithFinder($finder));

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/packages.json');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    /**
     * Unauthenticated request to / returns 401 when no public paths are configured.
     */
    public function testProcessRequiresAuthForBrowsingWhenNoPublicPaths(): void
    {
        $this->provider->setPublicPaths([]);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Unauthenticated request to a public vendor path is allowed.
     */
    public function testProcessAllowsUnauthenticatedAccessToPublicVendorPath(): void
    {
        $finder = (new Finder())->files();
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);
        $this->provider->setContainer($this->makeContainerWithFinder($finder));

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/acme/package.zip');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    /**
     * Unauthenticated request to a protected vendor path returns 401.
     */
    public function testProcessDeniesUnauthenticatedAccessToProtectedVendorPath(): void
    {
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/protected/package.zip');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Invalid credentials always return 401, even for browsing pages.
     */
    public function testProcessReturns401ForInvalidCredentials(): void
    {
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('correct', PASSWORD_BCRYPT)]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withHeader('Authorization', 'Basic ' . base64_encode('user:wrong'));

        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Unauthenticated access to /login returns 401 to trigger the browser dialog.
     */
    public function testProcessReturns401ForLoginWithoutCredentials(): void
    {
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/login');
        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Valid credentials on /login redirect to /.
     */
    public function testProcessRedirectsToHomeAfterSuccessfulLogin(): void
    {
        $finder = (new Finder())->files();
        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);
        $this->provider->setContainer($this->makeContainerWithFinder($finder));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/login')
            ->withHeader('Authorization', 'Basic ' . base64_encode('user:pass'));

        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    /**
     * Valid credentials on a regular page pass through to the route handler.
     */
    public function testProcessPassesThroughWithValidCredentials(): void
    {
        $finder = (new Finder())->files();
        $this->provider->setPublicPaths([]);
        $this->provider->setUserHashes(['user' => password_hash('pass', PASSWORD_BCRYPT)]);
        $this->provider->setContainer($this->makeContainerWithFinder($finder));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withHeader('Authorization', 'Basic ' . base64_encode('user:pass'));

        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Authenticated users with an 'allow' list must still see public packages.
     *
     * This covers both the web-UI case (/ and /packages.json) and the Composer
     * case where stored credentials are replayed for every request to the host.
     */
    public function testProcessIncludesPublicPackagesForAuthenticatedUserWithAllowList(): void
    {
        $finder = $this->createMock(Finder::class);

        // The Finder should receive path('protected') from the allow list AND
        // path('acme') from the public paths – in that order.
        $pathCalls = [];
        $finder->method('path')
            ->willReturnCallback(function (string $p) use ($finder, &$pathCalls): Finder {
                $pathCalls[] = $p;
                return $finder;
            });

        $users = ['user' => ['hash' => password_hash('pass', PASSWORD_BCRYPT), 'allow' => ['protected']]];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [Finder::class, $finder],
            ['users', $users],
        ]);

        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => $users['user']['hash']]);
        $this->provider->setContainer($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/packages.json')
            ->withHeader('Authorization', 'Basic ' . base64_encode('user:pass'));

        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['protected', 'acme'], $pathCalls);
    }

    /**
     * Authenticated users without an 'allow' list see all packages (no extra path() calls).
     *
     * Calling path() on an unconstrained Finder would incorrectly restrict results
     * to only the public paths, so we must not call applyPublicFilter() in this case.
     */
    public function testProcessDoesNotApplyPublicFilterWhenUserHasNoAllowList(): void
    {
        $finder = $this->createMock(Finder::class);

        // No path() calls should be made – the Finder must stay unrestricted.
        $finder->expects($this->never())->method('path');

        $users = ['user' => ['hash' => password_hash('pass', PASSWORD_BCRYPT)]];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [Finder::class, $finder],
            ['users', $users],
        ]);

        $this->provider->setPublicPaths(['/acme']);
        $this->provider->setUserHashes(['user' => $users['user']['hash']]);
        $this->provider->setContainer($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withHeader('Authorization', 'Basic ' . base64_encode('user:pass'));

        $response = $this->provider->process($request, $this->makePassThroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeContainerWithFinder(Finder $finder): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [Finder::class, $finder],
            ['users', []],
        ]);

        return $container;
    }

    private function makePassThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(200);
            }
        };
    }
}
