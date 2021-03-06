<?php

/*
 * This file is part of the PhpBotFramework.
 *
 * PhpBotFramework is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, version 3.
 *
 * PhpBotFramework is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace PhpBotFramework\Utilities;

/**
 * \addtogroup Modules
 * @{
 */
use PhpBotFramework\Exceptions\BotException;

use PhpBotFramework\Core\BasicBot;

/**
 * \class BotState
 * \brief Handle users status.
 */
class BotState
{
    private $bot;

    /** \brief Current status of the user. */
    public $status;

    /**
     * \addtogroup State
     * \brief Create a state-based bot using these methods.
     * \details The bot will answer in different ways based on its internal state.
     *
     * Below an example where we save user's credentials using bot states:
     *
     *     <?php
     *
     *     // Include the framework
     *     require './vendor/autoload.php';
     *
     *     // Define bot state
     *     define("SEND_USERNAME", 1);
     *     define("SEND_PASSWORD", 2);
     *
     *     // Create the class for the bot that will handle login
     *     class LoginBot extends PhpBotFramework\Bot {
     *
     *         // Add the function for processing messages
     *         protected function processMessage($message) {
     *             switch($this->getStatus()) {
     *                 case SEND_USERNAME:
     *                     $this->sendMessage("Please, send your password.");
     *
     *                     // Update the bot state
     *                     $this->setStatus(SEND_PASSWORD);
     *                     break;
     *
     *                 // Or if we are expecting a password from the user
     *                 case SEND_PASSWORD:
     *                     $this->sendMessage("The registration is complete");
     *                     break;
     *             }
     *         }
     *
     *     }
     *
     *     $bot = new LoginBot("token");
     *
     *     $bot->redis = new Redis();
     *     $bot->redis->connect('127.0.0.1');
     *
     *     // Create the answer to the <code>/start</code> command
     *     $start_closure = function($bot, $message) {
     *         $bot->sendMessage("Please, send your username.");
     *         $bot->setStatus(SEND_USERNAME);
     *     };
     *
     *     $bot->addMessageCommand("start", $start_closure);
     *     $bot->run(GETUPDATES);
     * @{
     */

    public function __construct(BasicBot &$bot)
    {
        $this->bot = $bot;
    }

    /**
     * \brief Get current user status from Redis and set it in status variable.
     * \details Throws an exception if the Redis connection is missing.
     * @param int $default_status <i>Optional</i>. The default status to return
     * if there is no status for the current user.
     * @return int The status for the current user, $default_status if missing.
     */
    public function getStatus(int $default_status = -1) : int
    {
        $chat_id = $this->bot->chat_id;
        $redis = $this->bot->getRedis();
        if ($redis->exists($chat_id . ':status')) {
            $this->status = $redis->get($chat_id . ':status');
            return $this->status;
        }

        $redis->set($chat_id . ':status', $default_status);
        $this->status = $default_status;
        return $this->status;
    }

    /**
     * \brief Set the status of the bot in both Redis and $status.
     * \details Throws an exception if the Redis connection is missing.
     * @param int $status The new status of the bot.
     */
    public function setStatus(int $status)
    {
        $redis = $this->bot->getRedis();
        $redis->set($this->bot->chat_id . ':status', $status);

        $this->status = $status;
    }

    /** @} */
}
