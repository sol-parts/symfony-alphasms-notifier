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

use Symfony\Component\RemoteEvent\Event\Sms\SmsEvent;

$wh = new SmsEvent(SmsEvent::DELIVERED, '100500', \json_decode(\file_get_contents(\str_replace('.php', '.json', __FILE__)), true, flags: \JSON_THROW_ON_ERROR));
$wh->setRecipientPhone('');

return $wh;
