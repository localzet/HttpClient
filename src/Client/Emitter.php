<?php
/**
 * @package     HTTP Client
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Zorin Projects S.P.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\HTTP\Client;

class Emitter
{
    /**
     * Константы для обозначения типов слушателей
     */
    private const ONCE = true;
    private const NOT_ONCE = false;

    /**
     * Массив для хранения слушателей событий
     * @var array
     */
    private array $_eventListenerMap = [];

    /**
     * Добавление слушателя, который будет вызываться при каждом событии
     *
     * @param string $event_name Имя события
     * @param callable $listener Слушатель события
     * @return self Возвращает текущий объект для цепочки вызовов
     */
    public function on(string $event_name, callable $listener): self
    {
        $this->emit('newListener', $event_name, $listener);
        $this->addListener($event_name, $listener, self::NOT_ONCE);
        return $this;
    }

    /**
     * Добавление слушателя, который будет вызываться только один раз
     *
     * @param string $event_name Имя события
     * @param callable $listener Слушатель события
     * @return self Возвращает текущий объект для цепочки вызовов
     */
    public function once(string $event_name, callable $listener): self
    {
        $this->addListener($event_name, $listener, self::ONCE);
        return $this;
    }

    /**
     * Добавление слушателя события
     *
     * @param string $event_name Имя события
     * @param callable $listener Слушатель события
     * @param bool $once Определяет, будет ли слушатель вызываться только один раз
     */
    private function addListener(string $event_name, callable $listener, bool $once): void
    {
        $this->_eventListenerMap[$event_name][] = [$listener, $once];
    }

    /**
     * Удаление слушателя события
     *
     * @param string $event_name Имя события
     * @param callable $listener Слушатель события
     * @return self Возвращает текущий объект для цепочки вызовов
     */
    public function removeListener(string $event_name, callable $listener): self
    {
        if (!isset($this->_eventListenerMap[$event_name])) {
            return $this;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            if ($item[0] === $listener) {
                $this->emit('removeListener', $event_name, $listener);
                unset($this->_eventListenerMap[$event_name][$key]);
            }
        }
        if (empty($this->_eventListenerMap[$event_name])) {
            unset($this->_eventListenerMap[$event_name]);
        }
        return $this;
    }

    /**
     * Удаление всех слушателей события
     *
     * @param string|null $event_name Имя события
     * @return self Возвращает текущий объект для цепочки вызовов
     */
    public function removeAllListeners(?string $event_name = null): self
    {
        $this->emit('removeListener', $event_name);
        if (null === $event_name) {
            $this->_eventListenerMap = [];
            return $this;
        }
        unset($this->_eventListenerMap[$event_name]);
        return $this;
    }

    /**
     * Получение слушателей события
     *
     * @param string $event_name Имя события
     * @return array Массив слушателей события
     */
    public function listeners(string $event_name): array
    {
        if (empty($this->_eventListenerMap[$event_name])) {
            return [];
        }
        $listeners = [];
        foreach ($this->_eventListenerMap[$event_name] as $item) {
            $listeners[] = $item[0];
        }
        return $listeners;
    }

    /**
     * Вызов события
     *
     * @param string|null $event_name Имя события
     * @return bool Возвращает true, если событие было вызвано, иначе false
     */
    public function emit(?string $event_name = null): bool
    {
        if (empty($event_name) || empty($this->_eventListenerMap[$event_name])) {
            return false;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            $args = func_get_args();
            unset($args[0]);
            call_user_func_array($item[0], $args);
            // once ?
            if ($item[1]) {
                unset($this->_eventListenerMap[$event_name][$key]);
                if (empty($this->_eventListenerMap[$event_name])) {
                    unset($this->_eventListenerMap[$event_name]);
                }
            }
        }
        return true;
    }
}