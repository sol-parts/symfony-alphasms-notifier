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

namespace SolParts\SymfonyAlphaSmsNotifier;

use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
final class AlphaSmsTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): AlphaSmsTransport
    {
        $scheme = $dsn->getScheme();

        if ('alphasms' !== $scheme) {
            throw new UnsupportedSchemeException($dsn, 'alphasms', $this->getSupportedSchemes());
        }

        $authKey = $this->getUser($dsn);
        $from = $dsn->getRequiredOption('from');
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port = $dsn->getPort();

        return (new AlphaSmsTransport($authKey, $from, $this->client, $this->dispatcher))->setHost($host)->setPort($port);
    }

    protected function getSupportedSchemes(): array
    {
        return ['alphasms'];
    }
}
