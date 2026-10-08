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

use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
final class AlphaSmsOptions implements MessageOptionsInterface
{
    /** @var array<mixed> */
    private array $options = [];

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * Відправка Viber з переотправкою по SMS у разі неможливості доставки.
     *
     * viber_type транспорт визначає сам за реально заданими viber_image і
     * viber_link, тож задавати їх усі не обов'язково.
     *
     * @return $this
     */
    public function viber_if_possible(bool $state): static
    {
        $this->options['viber_if_possible'] = $state;

        return $this;
    }

    /**
     * Окремий текст Viber. Порожнє значення залишає текст SMS.
     *
     * @return $this
     */
    public function viber_text(string $text): static
    {
        $this->options['viber_text'] = '' !== \trim($text) ? $text : null;

        return $this;
    }

    /**
     * @param int $id - Унікальний ідентифікатор повідомлення в системі клієнта
     *
     * @return $this
     */
    public function id(int $id): static
    {
        $this->options['id'] = $id;

        return $this;
    }

    /**
     * Ігнорується разом із viber_if_possible(true): у типі viber+sms AlphaSMS
     * приймає лише viber_lifetime.
     *
     * @param int $sms_lifetime Час життя SMS-повідомлення в секундах.
     *                          Мін. значення: 1 година (3600 секунд).
     *                          Макс. значення: 3 дні (259200 секунд)
     *                          За замовчуванням: 2 дні (172800 секунд)
     *
     * @return $this
     */
    public function sms_lifetime(int $sms_lifetime): static
    {
        $this->options['sms_lifetime'] = $sms_lifetime;

        return $this;
    }

    /**
     * @param bool $short_link Короткі та відстежувані посилання
     *
     * @return $this
     */
    public function short_link(bool $short_link): static
    {
        $this->options['short_link'] = $short_link;

        return $this;
    }

    /**
     * @param string $hook URL-адреса скрипта, куди буде відправлено статус доставки повідомлення
     *
     * @return $this
     */
    public function hook(string $hook): static
    {
        $this->options['hook'] = $hook;

        return $this;
    }

    /**
     * @param string $viber_image Посилання на зображення, яке буде надіслано в повідомленні
     *
     * @return $this
     */
    public function viber_image(string $viber_image): static
    {
        $this->options['viber_image'] = $viber_image;

        return $this;
    }

    /**
     * @param string $viber_link Посилання, за яким буде здійснено перехід після натискання кнопки
     *
     * @return $this
     */
    public function viber_link(string $viber_link): static
    {
        $this->options['viber_link'] = $viber_link;

        return $this;
    }

    /**
     * @param string $viber_button Підпис, який буде відображатися на кнопці
     *
     * @return $this
     */
    public function viber_button(string $viber_button): static
    {
        $this->options['viber_button'] = $viber_button;

        return $this;
    }

    /**
     * @param int $viber_lifetime Час життя Viber-повідомлення в секундах
     *                            Мін. значення: 60 секунд
     *                            За замовчуванням: 2 дні (172800 секунд)
     *
     * @return $this
     */
    public function viber_lifetime(int $viber_lifetime): static
    {
        $this->options['viber_lifetime'] = $viber_lifetime;

        return $this;
    }

    public function getRecipientId(): ?string
    {
        return null;
    }
}
