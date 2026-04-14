<?php

declare(strict_types=1);

namespace Rarst\ReleaseBelt\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rarst\ReleaseBelt\Provider\AuthenticationProvider;

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

            public function exposedGetPublicPaths(): array
            {
                return $this->getPublicPaths();
            }
        };
    }

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
}
