# Symfony AlphaSMS Notifier

Provides [AlphaSMS](https://alphasms.ua/) integration for Symfony Notifier.
The package supports SMS, Viber with SMS fallback, account balance checks, and
delivery-status webhooks.

## Installation

```bash
composer require sol-parts/symfony-alphasms-notifier
```

## Standalone usage

The transport can be used without the Symfony Full Stack Framework:

```php
use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsOptions;
use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsTransport;
use Symfony\Component\Notifier\Message\SmsMessage;

$transport = new AlphaSmsTransport('api-key', 'ExampleShop');
$options = (new AlphaSmsOptions())->id(100500);
$message = new SmsMessage(
    phone: '+380771234567',
    subject: 'Your order has been shipped.',
    options: $options,
);

$sentMessage = $transport->send($message);

dump($sentMessage->getMessageId());
// '100500'

dump($sentMessage->getInfo());
// The complete decoded AlphaSMS API response.
```

### Checking the balance

```php
$balance = $transport->balance();

dump($balance);
// '125.50 UAH'
```

## Symfony Full Stack Framework

Add the AlphaSMS DSN to your environment:

```dotenv
# .env.local
ALPHASMS_DSN=alphasms://API_KEY@default?from=ExampleShop
```

Register the transport factory:

```yaml
# config/services.yaml
services:
    SolParts\SymfonyAlphaSmsNotifier\AlphaSmsTransportFactory:
        autowire: true
        tags: ['texter.transport_factory']
```

Configure the texter transport:

```yaml
# config/packages/notifier.yaml
framework:
    notifier:
        texter_transports:
            alphasms: '%env(ALPHASMS_DSN)%'
```

Where:

- `API_KEY` is your AlphaSMS API key;
- `FROM` is your registered sender name.

URL-encode the API key and sender name if they contain characters reserved in a
URI.

Inject Symfony's `TexterInterface` to send a message:

```php
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

final class OrderNotifier
{
    public function __construct(
        private readonly TexterInterface $texter,
    ) {
    }

    public function sendShippingNotice(string $phone): void
    {
        $this->texter->send(new SmsMessage(
            $phone,
            'Your order has been shipped.',
        ));
    }
}
```

## Message options

Use `AlphaSmsOptions` to configure an AlphaSMS message:

```php
use SolParts\SymfonyAlphaSmsNotifier\AlphaSmsOptions;
use Symfony\Component\Notifier\Message\SmsMessage;

$options = (new AlphaSmsOptions())
    ->id(100500)
    ->sms_lifetime(172800)
    ->short_link(true)
    ->hook('https://example.com/webhook/alpha_sms');

$message = new SmsMessage(
    phone: '+380771234567',
    subject: 'Track your shipment: https://example.com/orders/100500',
    options: $options,
);
```

To try Viber first and fall back to SMS when delivery is unavailable, enable
`viber_if_possible()` and add the Viber options you need:

```php
$options = (new AlphaSmsOptions())
    ->viber_if_possible(true)
    ->viber_image('https://example.com/images/message.png')
    ->viber_link('https://example.com/orders/100500')
    ->viber_button('View order')
    ->viber_lifetime(172800);
```

The transport derives the AlphaSMS `viber_type` from the options actually set,
so a plain text Viber message is a single call:

```php
$options = (new AlphaSmsOptions())->viber_if_possible(true);
```

AlphaSMS accepts only `viber_lifetime` for the `viber+sms` type, so
`sms_lifetime()` is ignored while `viber_if_possible(true)` is set.

## Delivery-status webhook

Register the AlphaSMS request parser:

```yaml
# config/services.yaml
services:
    notifier.webhook.request_parser.alpha_sms:
        class: SolParts\SymfonyAlphaSmsNotifier\Webhook\AlphaSmsRequestParser
```

Configure the webhook routing and use your AlphaSMS API key as the signing
secret:

```yaml
# config/packages/framework.yaml
framework:
    webhook:
        routing:
            alpha_sms:
                service: notifier.webhook.request_parser.alpha_sms
                secret: '%env(ALPHASMS_WEBHOOK_SECRET)%'
```

The resulting endpoint is `/webhook/alpha_sms`. Add a consumer for the parsed
SMS events:

```php
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Sms\SmsEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('alpha_sms')]
final class AlphaSmsEventConsumer implements ConsumerInterface
{
    public function consume(RemoteEvent $event): void
    {
        if (!$event instanceof SmsEvent) {
            return;
        }

        // Handle the delivery status.
    }
}
```

## Resources

- [AlphaSMS JSON API](https://docs.alphasms.ua/api/json/send_sms/)
- [AlphaSMS Viber with SMS fallback](https://docs.alphasms.ua/api/json/viber/resending_sms/)
- [AlphaSMS webhook](https://docs.alphasms.ua/api/json/webhook/)
- [Symfony Notifier SMS channel](https://symfony.com/doc/7.3/notifier.html#sms-channel)
- [Symfony Webhook](https://symfony.com/doc/7.3/webhook.html)
