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

use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsOptions;
use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class AlphaSmsTransportTest extends TransportTestCase
{
    public static function createTransport(?HttpClientInterface $client = null): AlphaSmsTransport
    {
        return new AlphaSmsTransport('authKey', 'sender', $client ?? new MockHttpClient());
    }

    public static function supportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('380771234567', 'Hi!')];
    }

    public static function unsupportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hi!')];
    }

    public static function toStringProvider(): iterable
    {
        yield ['alphasms://alphasms.ua?from=sender', self::createTransport()];
    }

    public function testSuccessfulSend(): void
    {
        $body = [
            'success' => true,
            'data' => [
                [
                    'success' => true,
                    'data' => [
                        'id' => 175743824636625,
                        'msg_id' => 1419790613,
                        'data' => 1,
                        'parts' => 1,
                    ],
                ],
            ],
        ];

        $response = new JsonMockResponse(body: $body, info: ['http_code' => 200]);

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response): ResponseInterface {
            self::assertSame('POST', $method);
            self::assertSame('https://alphasms.ua/api/json.php', $url);

            $body = \json_decode((string) $options['body'], true);

            unset($body['data'][0]['id']);
            self::assertSame([
                'auth' => 'authKey',
                'data' => [
                    [
                        'type' => 'sms',
                        'sms_signature' => 'sender',
                        'phone' => 380771234567,
                        'sms_message' => 'Текст sms. Перевірка!',
                    ],
                ],
            ], $body);

            return $response;
        });

        $message = new SmsMessage('+380771234567', 'Текст sms. Перевірка!');

        $transport = self::createTransport($client);
        $sentMessage = $transport->send($message);

        self::assertInstanceOf(SentMessage::class, $sentMessage);
        self::assertSame('175743824636625', $sentMessage->getMessageId());
        self::assertSame($body, $sentMessage->getInfo());
    }

    /**
     * Viber без картинки й посилання: viber_type мусить звузитись до 'text'.
     * Захардкоджений 'text+image+link' змусив би AlphaSMS вимагати поля, яких
     * у запиті немає, і відхилити відправлення разом із SMS-фолбеком.
     */
    public function testViberTypeFollowsProvidedOptions(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): ResponseInterface {
            $body = \json_decode((string) $options['body'], true);

            self::assertSame('viber+sms', $body['data'][0]['type']);
            self::assertSame('text', $body['data'][0]['viber_type']);

            return new JsonMockResponse(
                body: ['success' => true, 'data' => [['success' => true, 'data' => ['id' => 100500]]]],
                info: ['http_code' => 200],
            );
        });

        $message = new SmsMessage(
            phone: '+380771234567',
            subject: 'Your order has been shipped.',
            options: (new AlphaSmsOptions())->viber_if_possible(true),
        );

        self::assertSame('100500', self::createTransport($client)->send($message)->getMessageId());
    }

    /**
     * A status lookup must send the client-side id — the one the transport
     * generated or the caller passed through the options — and hand back the
     * whole payload rather than the status string alone. `updated` is what
     * separates a message still moving through the gateway from one frozen in
     * the same state for hours, and that difference is the entire point of
     * asking when an SMS never arrived.
     */
    public function testStatusReturnsMessagePayload(): void
    {
        $payload = [
            'id' => '175743824636625',
            'msg_id' => '1419790613',
            'type' => 'sms',
            'status' => 'DELIVERED',
            'updated' => '2024-01-31T12:34:00+02:00',
        ];

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($payload): ResponseInterface {
            self::assertSame('POST', $method);
            self::assertSame('https://alphasms.ua/api/json.php', $url);
            self::assertSame([
                'auth' => 'authKey',
                'data' => [
                    [
                        'type' => 'status',
                        'id' => 175743824636625,
                    ],
                ],
            ], \json_decode((string) $options['body'], true));

            return new JsonMockResponse(
                body: ['success' => true, 'data' => [['success' => true, 'data' => $payload]]],
                info: ['http_code' => 200],
            );
        });

        self::assertSame($payload, self::createTransport($client)->status(175743824636625));
    }

    /**
     * AlphaSMS reports an unknown id as success=false on the data item while
     * the envelope itself stays success=true with HTTP 200 — the shape you get
     * when the gateway-side msg_id is passed instead of the client-side id.
     * Checking only the envelope would return an empty payload as if the
     * lookup had succeeded, so the per-item flag must raise.
     */
    public function testStatusFailsWhenTheMessageIsUnknown(): void
    {
        $client = new MockHttpClient(static fn (): ResponseInterface => new JsonMockResponse(
            body: ['success' => true, 'data' => [['success' => false, 'error' => 'Message not found']]],
            info: ['http_code' => 200],
        ));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/Message not found/');

        self::createTransport($client)->status(1419790613);
    }

    public function testSuccessfulViberSendWithSmsFallback(): void
    {
        $body = [
            'success' => true,
            'data' => [
                [
                    'success' => true,
                    'data' => [
                        'id' => 100500,
                        'msg_id' => 1419790613,
                        'data' => 1,
                        'parts' => 1,
                    ],
                ],
            ],
        ];

        $response = new JsonMockResponse(body: $body, info: ['http_code' => 200]);

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response): ResponseInterface {
            self::assertSame('POST', $method);
            self::assertSame('https://alphasms.ua/api/json.php', $url);
            self::assertSame([
                'auth' => 'authKey',
                'data' => [
                    [
                        'type' => 'viber+sms',
                        'viber_type' => 'text+image+link',
                        'viber_signature' => 'sender',
                        'viber_message' => 'Your order has been shipped.',
                        'viber_image' => 'https://example.com/message.png',
                        'viber_link' => 'https://example.com/orders/100500',
                        'viber_button' => 'View order',
                        'viber_lifetime' => 172800,
                        'id' => 100500,
                        'sms_signature' => 'sender',
                        'phone' => 380771234567,
                        'sms_message' => 'Your order has been shipped.',
                        'short_link' => true,
                        'hook' => 'https://example.com/webhook/alpha_sms',
                    ],
                ],
            ], \json_decode((string) $options['body'], true));

            return $response;
        });

        $messageOptions = (new AlphaSmsOptions())
            ->viber_if_possible(true)
            ->viber_image('https://example.com/message.png')
            ->viber_link('https://example.com/orders/100500')
            ->viber_button('View order')
            ->viber_lifetime(172800)
            ->id(100500)
            ->short_link(true)
            ->hook('https://example.com/webhook/alpha_sms');
        $message = new SmsMessage(
            phone: '+380771234567',
            subject: 'Your order has been shipped.',
            options: $messageOptions,
        );

        $sentMessage = self::createTransport($client)->send($message);

        self::assertInstanceOf(SentMessage::class, $sentMessage);
        self::assertSame('100500', $sentMessage->getMessageId());
        self::assertSame($body, $sentMessage->getInfo());
    }
}
