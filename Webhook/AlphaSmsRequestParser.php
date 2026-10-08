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

namespace SolParts\SymfonyAlphaSmsNotifier\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HeaderRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\Event\Sms\SmsEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 *
 * @see https://docs.alphasms.ua/api/json/webhook
 * @see https://docs.alphasms.ua/api/json/references/message_statuses
 */
final class AlphaSmsRequestParser extends AbstractRequestParser
{
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
            new HeaderRequestMatcher(['Content-Type', 'X-Signature']),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): SmsEvent
    {
        // Підпис перевіряємо до розбору тіла: інакше неавтентифікований запит
        // спершу повністю декодується, а код відповіді (406 проти 403) видає
        // склад очікуваного payload.
        $this->validateSignature($request, $secret);

        $payload = $request->toArray();
        $status = \is_string($payload['status'] ?? null) ? $payload['status'] : null;

        // status потрібен нижче в match, тож перевіряємо його разом з id і type:
        // без цього callback без статусу (напр. відповідь Viber 2-Way) давав би
        // «Undefined array key» і 500 замість чистого 406.
        if (!isset($payload['id'], $payload['type']) || null === $status) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        $name = match ($status) {
            'ACCEPTED', 'QUEUED', 'ROUTING' => \strtolower($status),
            'DELIVERED', 'READ', 'REPLIED' => SmsEvent::DELIVERED,
            'DELETED', 'EXPIRED', 'FILTERED',
            'INVALID DESTINATION ADDRESS', 'INVALID SOURCE ADDRESS',
            'NO ROUTE', 'PARTIALLY DELIVERED', 'REJECTED',
            'SIM FULL', 'UNDELIVERABLE', 'UNKNOWN' => SmsEvent::FAILED,
            default => throw new RejectWebhookException(406, \sprintf('Unsupported event "%s".', $status)),
        };

        // Ідентифікатор події — payload.id, той самий ідентифікатор у системі
        // клієнта, який транспорт віддає через SentMessage::getMessageId().
        // По msg_id (ідентифікатор шлюзу) відправлення не знайти.
        $event = new SmsEvent($name, (string) $payload['id'], $payload);
        $event->setRecipientPhone('');

        return $event;
    }

    private function validateSignature(Request $request, string $secret): void
    {
        $computedSignature = \hash('sha256', $request->getContent() . $secret);

        if (!\hash_equals($computedSignature, (string) $request->headers->get('x-signature'))) {
            throw new RejectWebhookException(403, 'Invalid signature.');
        }
    }
}
