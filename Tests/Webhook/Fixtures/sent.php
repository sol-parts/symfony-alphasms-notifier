<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Sms\SmsEvent;

$wh = new SmsEvent(SmsEvent::DELIVERED, '100500', \json_decode(\file_get_contents(\str_replace('.php', '.json', __FILE__)), true, flags: \JSON_THROW_ON_ERROR));
$wh->setRecipientPhone('');

return $wh;
