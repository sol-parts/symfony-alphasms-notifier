<?php

/*
 * This file is part of the Sol.parts package.
 *
 * (c) Andrii Didenko <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\SymfonyAlphaSmsNotifier\Tests\Webhook;

use SolParts\SymfonyAlphaSmsNotifier\Webhook\AlphaSmsRequestParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Symfony\Component\Webhook\Test\AbstractRequestParserTestCase;

class AlphaSmsRequestParserTest extends AbstractRequestParserTestCase
{
    /**
     * Секретом вебхука AlphaSMS є API-ключ. Він має бути непорожнім, інакше
     * sha256(body . secret) і sha256(secret . body) дають однаковий хеш і
     * помилка в порядку конкатенації лишилася б непоміченою.
     */
    private const SECRET = 'api-key';

    /**
     * sha256 від байтів Fixtures/sent.json з дописаним у кінець SECRET —
     * формула підпису з документації AlphaSMS: sha256(json_body + api_key).
     * При зміні фікстури значення потрібно перерахувати.
     */
    private const SENT_FIXTURE_SIGNATURE = 'd49883e980ddf9969befb8586257b676d77ebdd66305e6d029f69eb66f0738bd';

    public function testInvalidSignatureIsRejected(): void
    {
        $request = $this->createRequest('{"id":100500,"msg_id":123456789,"type":"viber","status":"READ"}');
        $request->headers->set('X-Signature', \str_repeat('0', 64));

        $this->expectException(RejectWebhookException::class);

        $this->createRequestParser()->parse($request, self::SECRET);
    }

    protected function createRequestParser(): RequestParserInterface
    {
        return new AlphaSmsRequestParser();
    }

    protected function getSecret(): string
    {
        return self::SECRET;
    }

    protected function createRequest(string $payload): Request
    {
        return Request::create('/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => self::SENT_FIXTURE_SIGNATURE,
        ], $payload);
    }
}
