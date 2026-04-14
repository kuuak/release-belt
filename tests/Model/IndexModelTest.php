<?php
declare(strict_types=1);

namespace Rarst\ReleaseBelt\Tests\Model;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Rarst\ReleaseBelt\Model\IndexModel;
use Rarst\ReleaseBelt\UrlGenerator;

class IndexModelTest extends TestCase
{
    public function testGetContext(): void
    {
        $packages = [
            'package1' => [
                '1.0' => [],
                '2.0' => [
                    'type' => 'wordpress-plugin',
                ],
            ],
            'package2' => [
                '1.1' => [
                    'foo' => 'bar',
                ],
            ],
        ];

        $urlGeneratorDummy = $this->getMockBuilder(UrlGenerator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $indexModel = new IndexModel($packages, $urlGeneratorDummy);
        $context    = $indexModel->getContext();

        $this->assertEquals([
            [
                'name'            => 'package1',
                'latest'          => '2.0',
                'type'            => 'wordpress-plugin',
                'versions'        => [
                    [
                        'type' => 'wordpress-plugin',
                    ],
                    [],
                ],
                'moreVersions'    => [],
                'hasMoreVersions' => false,
            ],
            [
                'name'            => 'package2',
                'latest'          => '1.1',
                'versions'        => [
                    [
                        'foo' => 'bar',
                    ],
                ],
                'moreVersions'    => [],
                'hasMoreVersions' => false,
                'last'            => true,
            ],
        ], $context['packages']);
    }

    public function testGetContextWithManyVersions(): void
    {
        $packages = [
            'package1' => [
                '1.0' => ['v' => '1.0'],
                '2.0' => ['v' => '2.0'],
                '3.0' => ['v' => '3.0'],
                '4.0' => ['v' => '4.0'],
                '5.0' => ['v' => '5.0'],
            ],
        ];

        $urlGeneratorDummy = $this->getMockBuilder(UrlGenerator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $indexModel = new IndexModel($packages, $urlGeneratorDummy);
        $context    = $indexModel->getContext();

        $this->assertCount(3, $context['packages'][0]['versions']);
        $this->assertCount(2, $context['packages'][0]['moreVersions']);
        $this->assertTrue($context['packages'][0]['hasMoreVersions']);
        $this->assertEquals('5.0', $context['packages'][0]['latest']);
        $this->assertEquals(['v' => '5.0'], $context['packages'][0]['versions'][0]);
        $this->assertEquals(['v' => '4.0'], $context['packages'][0]['versions'][1]);
        $this->assertEquals(['v' => '3.0'], $context['packages'][0]['versions'][2]);
        $this->assertEquals(['v' => '2.0'], $context['packages'][0]['moreVersions'][0]);
        $this->assertEquals(['v' => '1.0'], $context['packages'][0]['moreVersions'][1]);
    }
}
