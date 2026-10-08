<?php

/*
 * This file is part of SOLPARTS
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\SymfonyAlphaSmsNotifier\Tests;

use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsTransportFactory;
use Symfony\Component\Notifier\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Notifier\Test\IncompleteDsnTestTrait;
use Symfony\Component\Notifier\Test\MissingRequiredOptionTestTrait;

final class AlphaSmsTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    use IncompleteDsnTestTrait;
    use MissingRequiredOptionTestTrait;

    public function createFactory(): AlphaSmsTransportFactory
    {
        return new AlphaSmsTransportFactory();
    }

    public static function createProvider(): iterable
    {
        yield [
            'alphasms://alphasms.ua?from=sender',
            'alphasms://api-key@default?from=sender',
        ];

        yield [
            'alphasms://alphasms.ua?from=Example+Shop',
            'alphasms://api-key@default?from=Example Shop',
        ];
    }

    public static function supportsProvider(): iterable
    {
        yield [true, 'alphasms://api-key@default?from=sender'];
        yield [false, 'something-else://api-key@default?from=sender'];
    }

    public static function missingRequiredOptionProvider(): iterable
    {
        yield 'missing option: from' => ['alphasms://api-key@default'];
    }

    public static function unsupportedSchemeProvider(): iterable
    {
        yield ['something-else://api-key@default?from=sender'];
        yield ['something-else://api-key@default'];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield ['alphasms://default?from=sender'];
    }
}
