<?php

/**
 * @package     Localzet HTTP Client
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
*/


namespace localzet\HTTP;

use localzet\Server;
use RuntimeException;
use Throwable;

/**
 * Класс ParallelClient представляет собой клиента, который может выполнять параллельные запросы.
 */
#[\AllowDynamicProperties]
class ParallelClient extends Client
{
    /**
     * Буферная очередь для хранения запросов, которые должны быть выполнены параллельно.
     */
    protected array $_buffer_queues = [];

    /**
     * Добавляет запрос в буферную очередь.
     *
     * @param string $url URL-адрес для запроса.
     * @param array $options Опции для запроса.
     */
    public function push(string $url, array $options = []): void
    {
        $this->_buffer_queues[] = [$url, $options];
    }

    /**
     * Добавляет набор запросов в буферную очередь.
     *
     * @param array $set Набор запросов для добавления в очередь.
     */
    public function batch(array $set): void
    {
        $this->_buffer_queues = array_merge($this->_buffer_queues, $set);
    }

    /**
     * Выполняет все запросы в буферной очереди и возвращает результаты.
     *
     * @param bool $errorThrow Если true, то при ошибке будет выброшено исключение.
     * @return array Возвращает массив результатов запросов.
     * @throws RuntimeException|Throwable Выбрасывает исключение, если текущая среда не поддерживает Unix.
     */
    public function await(bool $errorThrow = false): array
    {
        if (!is_unix()) {
            throw new RuntimeException('Please use Linux.');
        }

        $queues = $this->_buffer_queues;

        $result = [];

        $suspensionArr = array_fill(0, count($queues), Server::$globalEvent->getSuspension());

        foreach ($queues as $index => $each) {
            $suspension = $suspensionArr[$index];
            $options = $each[1];

            $options['success'] = function ($response) use (&$result, &$suspension, $options, $index) {
                $result[$index] = [true, $response];
                $suspension->resume();
                // custom callback
                if (!empty($options['success'])) {
                    call_user_func($options['success'], $response);
                }
            };

            $options['error'] = function ($exception) use (&$result, &$suspension, $errorThrow, $options, $index) {
                $result[$index] = [false, $exception];
                try {
                    if ($errorThrow) {
                        $suspension->throw($exception);
                    } else {
                        $suspension->resume();
                    }
                } catch (Throwable $e) {
                    unset($suspension);
                }
                // custom callback
                if (!empty($options['error'])) {
                    call_user_func($options['error'], $exception);
                }
            };

            $this->request($each[0], $options);
        }

        foreach ($suspensionArr as $index => $suspension) {
            $suspension->suspend();
        }

        ksort($result);
        return $result;
    }

    /**
     * Откладывает обработку ошибки, вызывая обратный вызов ошибки.
     *
     * @param array $options Опции запроса, включающие обратные вызовы успеха и ошибки.
     * @param Throwable $exception Исключение для обработки.
     * @return void
     */
    #[\Override]
    protected function deferError(array $options, Throwable $exception): void
    {
        if (!empty($options['error'])) {
            call_user_func($options['error'], $exception);
        }
    }

}
