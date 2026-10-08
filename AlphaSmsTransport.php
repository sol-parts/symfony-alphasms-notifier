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

use Symfony\Component\Notifier\Exception\InvalidArgumentException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 *
 * @see https://docs.alphasms.ua/api/json/send_sms
 * @see https://docs.alphasms.ua/api/json/viber/resending_sms/
 */
final class AlphaSmsTransport extends AbstractTransport
{
    protected const HOST = 'alphasms.ua';

    private const API_PATH = '/api/json.php';

    /**
     * Лічильник суфікса `id`: null до першої відправки, далі — інкремент.
     */
    private ?int $idSequence = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $authKey,
        private readonly string $from,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        parent::__construct($client, $dispatcher);
    }

    public function __toString(): string
    {
        return \sprintf('alphasms://%s?from=%s', $this->getEndpoint(), \urlencode($this->from));
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }

    /**
     * Перевірка балансу.
     */
    public function balance(): string
    {
        $response = $this->httpClient()->request('POST', $this->apiEndpoint(), [
            'json' => [
                'auth' => $this->authKey,
                'data' => [[
                    'type' => 'balance',
                ]],
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $info = $response->toArray(false);
        } catch (\Exception|\Error $e) {
            throw new TransportException('AlphaSms API request execution error.', $response, 0, $e);
        }

        $amount = $info['data'][0]['data']['amount'] ?? null;

        // Без цієї перевірки відкликаний ключ повернувся б рядком-заглушкою
        // замість помилки, і збій облікових даних лишився б непоміченим.
        if (200 !== $statusCode || empty($info['success']) || !empty($info['error']) || null === $amount) {
            throw new TransportException(\sprintf('Unable to fetch the AlphaSms balance: "%s".', $this->formatApiError($info)), $response);
        }

        $currency = $info['data'][0]['data']['currency'] ?? '';

        return \trim(\sprintf('%s %s', $amount, $currency));
    }

    /**
     * Delivery status of an already sent message.
     *
     * The payload carries `id`, `msg_id`, `type` (sms, viber, rcs), `status`
     * and `updated`. Only DELIVERED, READ, REPLIED and PARTIALLY DELIVERED
     * mean the message reached the recipient — ACCEPTED and the rest are
     * intermediate states or failures, never "unknown".
     *
     * @param int|string $id client-side message id — the `id` option or the
     *                       sent message id, NOT the gateway-side `msg_id`
     *
     * @return array<string, mixed>
     *
     * @see https://docs.alphasms.ua/api/json/message_status/
     */
    public function status(int|string $id): array
    {
        $response = $this->httpClient()->request('POST', $this->apiEndpoint(), [
            'json' => [
                'auth' => $this->authKey,
                'data' => [[
                    'type' => 'status',
                    'id' => $id,
                ]],
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $info = $response->toArray(false);
        } catch (\Exception|\Error $e) {
            throw new TransportException('AlphaSms API request execution error.', $response, 0, $e);
        }

        $item = $info['data'][0] ?? [];
        $payload = $item['data'] ?? null;

        // An unknown id is reported as a per-item failure inside an otherwise
        // successful envelope, so the outer flags alone would pass it through
        // as a valid lookup with an empty payload.
        if (200 !== $statusCode
            || empty($info['success']) || !empty($info['error'])
            || empty($item['success']) || !empty($item['error'])
            || !\is_array($payload)
        ) {
            throw new TransportException(\sprintf('Unable to fetch the AlphaSms message status: "%s".', $this->formatApiError($info)), $response);
        }

        return $payload;
    }

    protected function doSend(MessageInterface $message): SentMessage
    {
        if (!$message instanceof SmsMessage) {
            throw new UnsupportedMessageTypeException(self::class, SmsMessage::class, $message);
        }

        $from = $message->getFrom() ?: $this->from;
        $options = $message->getOptions()?->toArray() ?? [];

        // AlphaSMS вимагає phone числом у міжнародному форматі: без +, пробілів
        // і дужок. Національний запис із провідним нулем при (int) мовчки
        // втратив би цей нуль і пішов би на чужий номер, тож відхиляємо явно.
        $phone = (string) \preg_replace('/\D+/', '', (string) $message->getPhone());

        if ('' === $phone || \str_starts_with($phone, '0')) {
            throw new InvalidArgumentException(\sprintf('The phone number "%s" must be in the international format expected by AlphaSms.', $message->getPhone()));
        }

        if (true === ($options['viber_if_possible'] ?? null)) {
            $dataItem = [
                'type' => 'viber+sms',
                'viber_type' => $this->viberType($options),
                'viber_signature' => $from,
                'viber_message' => $options['viber_text'] ?? $message->getSubject(),
                'viber_image' => $options['viber_image'] ?? null,
                'viber_link' => $options['viber_link'] ?? null,
                'viber_button' => $options['viber_button'] ?? null,
                'viber_lifetime' => $options['viber_lifetime'] ?? null,
            ];
        } else {
            $dataItem = [
                'type' => 'sms',
                'sms_lifetime' => $options['sms_lifetime'] ?? null,
            ];
        }

        $dataItem['id'] = $options['id'] ?? $this->uniqNumericIdMs();
        $dataItem['sms_signature'] = $from;
        $dataItem['phone'] = (int) $phone;
        $dataItem['sms_message'] = $message->getSubject();
        $dataItem['short_link'] = $options['short_link'] ?? null;
        $dataItem['hook'] = $options['hook'] ?? null;

        // Прибираємо лише незадані поля: array_filter() без колбека викинув би
        // й легальні false / 0 / '0' (напр. текст повідомлення «0»).
        $dataItem = \array_filter($dataItem, static fn (mixed $value): bool => null !== $value);

        $response = $this->httpClient()->request('POST', $this->apiEndpoint(), [
            'json' => [
                'auth' => $this->authKey,
                'data' => [$dataItem],
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the remote AlphaSms server.', $response, 0, $e);
        }

        try {
            $info = $response->toArray(false);
        } catch (DecodingExceptionInterface $e) {
            throw new TransportException('Could not decode body to an array.', $response, 0, $e);
        }

        $item = $info['data'][0] ?? [];

        // AlphaSMS повідомляє про збій прапорцем success на обох рівнях, і не
        // кожна відмова приходить із заповненим error, тож перевіряємо обидва.
        if (200 !== $statusCode
            || empty($info['success']) || !empty($info['error'])
            || empty($item['success']) || !empty($item['error'])
        ) {
            throw new TransportException(\sprintf('Unable to send the SMS with AlphaSms: "%s".', $this->formatApiError($info)), $response);
        }

        $messageId = $item['data']['id'] ?? null;

        $sentMessage = new SentMessage($message, (string) $this, $info);
        $sentMessage->setMessageId((string) $messageId);

        return $sentMessage;
    }

    /**
     * viber_type має перелічувати рівно ті блоки, які реально передані:
     * згаданий у типі image/link AlphaSMS вважає обов'язковим полем.
     *
     * @param array<mixed> $options
     */
    private function viberType(array $options): string
    {
        $viberType = 'text';

        if (null !== ($options['viber_image'] ?? null)) {
            $viberType .= '+image';
        }

        if (null !== ($options['viber_link'] ?? null)) {
            $viberType .= '+link';
        }

        return $viberType;
    }

    /**
     * @param array<mixed> $info
     */
    private function formatApiError(array $info): string
    {
        try {
            return \json_encode($info, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            return 'unknown error';
        }
    }

    private function apiEndpoint(): string
    {
        return \sprintf('https://%s%s', $this->getEndpoint(), self::API_PATH);
    }

    private function uniqNumericIdMs(): int
    {
        // Випадковий старт розводить паралельні процеси, інкремент — послідовні
        // відправки в межах однієї мілісекунди; суфікс завжди тризначний, щоб
        // id мав сталу довжину.
        $this->idSequence = null === $this->idSequence
            ? \random_int(0, 999)
            : ($this->idSequence + 1) % 1000;

        return (int) \sprintf('%d%03d', (int) \floor(\microtime(true) * 1000), $this->idSequence);
    }

    private function httpClient(): HttpClientInterface
    {
        return $this->client ?? throw new \LogicException('AlphaSms HTTP client is not initialized.');
    }
}
