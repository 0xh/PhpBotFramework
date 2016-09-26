<?php

namespace WiseDragonStd\HadesWrapper;

/*
 *
 * Class Bot to handle task like api request, or more specific api function(sendMessage, editMessageText, etc).
 * Usage example in webhook.php
 *
 */


class Bot extends CoreBot {
    // Update reference
    protected $update;
    // Inline Keyboard
    public $inline_keyboard;
    // Database connection using class Database (optional)
    public $database;
    // Pdo reference (optional)
    public $pdo;
    // Redis connection (optional)
    public $redis;
    // Language and localitation and localitation for multi-language bot
    public $language;
    public $localization;
    // Status of the bot
    protected $status;
    public $offset = 0;

    public function __destruct() {
        // Close database connection by deleting the reference
        $this->database = null;
        // Close redis connection if it is open
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    /*
     * Connect to database through the HadesSQL ORM,
     *
     * @param
     * $driver DBMS used
     * $dbname Name of the database
     * $user Username for logging
     * $password Passoword for the $username
     */
    public function &connectToDatabase($driver, $dbname, $user, $password) {
        $this->database = new Database($driver, $dbname, $user, $password);
        $this->pdo = $this->database->getPDO();
        return $this->database;
    }

    // Connect to redis giving $address parameter and optional append port to it (127.0.0.1:6379)
    public function &connectToRedis($address = '127.0.0.1') {
        try {
            $this->redis = new \Redis();
            $this->redis->connect($address);
        } catch (RedisException $e) {
            throw new BotException($e->getMessage());
        }
        return $redis;
    }

    public function &getChatID() {
        return $this->chat_id;
    }

    // Set chat_id of the bot
    public function setChatID($chat_id) {
        $this->chat_id = &$chat_id;
    }

    public function setChatIDRef(&$chat_id) {
        $this->chat_id = &$chat_id;
    }

    public function setDatabase($database) {
        $this->database = $database;
        $this->pdo = &$database->getPDO();
    }

    /*
     * Get language for the current user, reading it from the database
     */
    public function &getLanguage() {
        if (!isset($this->database)) {
            return 'en';
        }
        $sth = $this->pdo->prepare('SELECT "language" FROM "User" WHERE "chat_id" = :chat_id');
        $sth->bindParam(':chat_id', $this->chat_id);
        $sth->execute();
        $row = $sth->fetch();
        $sth = null;
        if (isset($row['language'])) {
            $this->language = $row['language'];
            return $row['language'];
        } else {
            $this->language = 'en';
            return $this->language;
        }
    }

    /*
     * Using Redis as a cache we store language in both database and Redis, read it from redis if
     * it exists (the key will expire in 1 day after it is set) or read it from the database and
     * store it in Redis
     */
    public function &getLanguageRedis() {
        if (!isset($this->redis)) {
            return 'en';
        }
        $is_language_set = $this->redis->exists($this->chat_id . ':language');
        if ($is_language_set) {
            $this->language = $this->redis->get($this->chat_id . 'language');
            return $this->language;
        } else {
            // TODO User Database instead of $pdo
            $this->redis->setEx($this->chat_id . ':language', 86400, getLanguage());
            return $this->language;
        }
    }

    /*
     * Set language for the current user, first it save it on db, then change it on redis if it exists
     * @param
     * $language New language
     */
    public function setLanguage($language) {
        if (!isset($this->database)) {
            exit;
        }
        $sth = $this->pdo->prepare('UPDATE "User" SET "language" = :language WHERE "chat_id" = :chat_id');
        $sth->bindParam(':language', $language);
        $sth->bindParam(':chat_id', $this->chat_id);
        try {
            $sth->execute();
        } catch (PDOException $e) {
            throw new BotException($e->getMessage());
        }
        $sth = null;
        if (isset($this->redis)) {
            $this->redis->setEx($this->chat_id . ':language', 86400, $language);
        }
    }

    public function setLocalization(&$localization) {
        $this->localization = &$localization;
    }

    // Get status of the bot, it will be both returned and setted in $this->status
    public function &getStatus() {
        $is_status_set = $this->redis->exists($this->chat_id . ':status');
        if ($is_status_set) {
            $this->status = $this->redis->get($this->chat_id . ':status');
            return $this->status;
        } else {
            $this->redis->set($this->chat_id . ':status', 0);
            $this->redis->set($this->chat_id . ':easter_egg', 1);
            $this->status = -1;
            return -1;
        }
    }

    // Set the status of the bot
    public function setStatus($status) {
        $this->redis->set($this->chat_id . ':status', $status);
    }

    // Read update and sent it to the right method
    public function processUpdate(&$update) {
        $this->update = &$update;
        if (isset($update['message'])) {
            $this->processMessage();
        } elseif (isset($update['callback_query'])) {
            $this->processCallbackQuery();
        } elseif (isset($update['inline_query'])) {
            $this->processInlineQuery();
        } elseif (isset($update['chosen_inline_result'])) {
            $this->processInlineResult();
        }
        return $update['update_id'];
    }

    protected function processMessage() {
        $message = &$this->update['message'];
        $this->chat_id = &$message['from']['id'];
    }

    protected function processCallbackQuery() {
        $callback_query = &$this->update['callback_query'];
        $this->chat_id = &$callback_query['from']['id'];
    }

    protected function processInlineQuery() {
        $inline_query = &$this->update['inline_query'];
        $this->chat_id = &$inline_query['from']['id'];
    }

    protected function processInlineResult() {
        $inline_result = &$this->update['chosen_inline_result'];
        $this->chat_id = &$inline_result['from']['id'];
    }

    /*
     * Get updates received by the bot, save the new offset to Redis and then process them
     * (https://core.telegram.org/bots/api#getupdates)
     * @param
     * $variable_name Name of the variable where the offset is saved on Redis
     */
    public function getUpdatesRedis($limit = 100, $timeout = 0, $variable_name = 'offset') {
        if (!isset($this->redis)) {
            exit;
        }
        $offset = $this->redis->get($variable_name);
        $updates = $this->getUpdates($offset, $limit, $timeout);

        if (!empty($updates)) {
            foreach ($updates as $key => $update) {
                 $this->processUpdate($update);
            }
        
            $this->redis->set($variable_name, $offset + count($updates));
        }
    }

     public function getNextUpdates() {
        $url = $this->api_url . 'getUpdates';

        return $this->exec_curl_request($url);
     }

     public function getUpdatesLocal($limit = 100, $timeout = 60) {
         while(true) {
             $parameters = [
                 'offset' => &$this->offset,
                 'limit' => &$limit,
                 'timeout' => &$timeout
             ];
             $url = $this->api_url . 'getUpdates?' . http_build_query($parameters);
             $updates = $this->exec_curl_request($url);
             if (!empty($updates)) {
                 if ($this->offset === 0) {
                     $this->offset = $updates[0]['update_id'];
                 }
                 foreach($updates as $key => $update) {
                     $this->processUpdate($update);
                 }

                 $this->offset += sizeof($updates);
             }
         }
     }

     /*
     * Get updates received by the bot, save the new offset to the database and then process them
     * (https://core.telegram.org/bots/api#getupdates)
     * @param
     * $table_name Name of the table where offset is saved in the database
     * $column_name Name of the column where the offset is saved in the database
     */
    public function getUpdatesDatabase($limit = 100, $timeout = 0, $table_name = 'telegram', $column_name = 'bot_offset') {
        if (!isset($this->database)) {
            exit;
        }
        $sth = $this->pdo->prepare('SELECT "' . $column_name . '" FROM "' . $table_name . '"');
        $sth->execute();
        $offset = $sth->fetchColumn();
        $sth = null;
        $updates = $this->getUpdates($offset, $limit, $timeout);

        if (!empty($updates)) {
            foreach($updates as $key => $update) {
                $this->processUpdate($update);
            }

            $sth = $this->pdo->prepare('UPDATE "' . $table_name . '" SET "' . $column_name . '" = :new_offset');
            $new_offset = $offset + sizeof($updates);
            $sth->bindParam(':new_offset', $new_offset);
            $sth->execute();
        }
    }

    public function adjustOffsetDatabase($limit = 100, $timeout = 0, $table_name = 'TELEGRAM', $column_name = 'offset') {
        if (!isset($this->database)) {
            exit;
        }
        $sth = $this->pdo->prepare('SELECT "' . $column_name . '" FROM "' . $table_name . '"');
        $sth->execute();
        $offset = $sth->fetchColumn();
        $sth = null;
        $updates = $this->getUpdates($offset, $limit, $timeout);

        if (!empty($updates)) {
            foreach($updates as $key => $update) {
                $new_offset = $this->processUpdate($update);
            }

            $sth = $this->pdo->prepare('UPDATE "' . $table_name . '" SET "' . $column_name . '" = :new_offset');
            $new_offset++;
            $sth->bindParam(':new_offset', $new_offset);
            $sth->execute();
            return $new_offset;
        }
    }

    public function adjustOffsetRedis($limit = 100, $timeout = 0, $variable_name = 'offset') {
        if (!isset($this->redis)) {
            exit;
        }
        $offset = $this->redis->get($variable_name);
        $updates = $this->getUpdates($offset, $limit, $timeout);

        if (!empty($updates)) {
            $this->redis->set('error', 1);
            foreach ($updates as $key => $update) {
                try {
                    $new_offset = $this->processUpdate($update);
                    $this->redis->set($variable_name, $new_offset);
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $new_offset++;
                }
            }

            $new_offset++;

            $this->redis->set($variable_name, $new_offset);
            $this->redis->set('error', 0);
            return $new_offset;
       }
    }

    public function &sendMessageRef(&$text, $parse_mode = 'HTML', $disable_web_preview = true, $disable_notification = false) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'text' => &$text,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => $disable_web_preview,
            'disable_notification' => $disable_notification
        ];
        $url = $this->api_url . 'sendMessage?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

     /*
     * Send a text message with an inline_keyboard (https://core.telegram.org/bots/api#sendmessage)
     * @param
     * $text Text of the message
     * $inline_keyboard Inlike keyboard array (https://core.telegram.org/bots/api#inlinekeyboardmarkup)
     * $parse_mode Parse mode of the message (https://core.telegram.org/bots/api#formatting-options)
     */
    public function &sendMessageKeyboard(&$text, &$inline_keyboard, $parse_mode = 'HTML', $disable_web_preview = true, $disable_notification = false) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'text' => &$text,
            'parse_mode' => $parse_mode,
            'reply_markup' => &$inline_keyboard,
            'disable_web_page_preview' => $disable_web_preview,
            'disable_notification' => $disable_notification
        ];
        $url = $this->api_url . 'sendMessage?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    /*
     * Send a text message with an inline keyboard replying to another one (https://core.telegram.org/bots/api#sendmessage)
     * @param
     * $text Text of the message
     * $message_id Id of the message the bot will reply
     * $inline_keyboard Inlike keyboard array (https://core.telegram.org/bots/api#inlinekeyboardmarkup)
     * $parse_mode Parse mode of the message (https://core.telegram.org/bots/api#formatting-options)
     */
    public function &sendReplyMessageKeyboard(&$text, &$inline_keyboard, &$message_id, $parse_mode = 'HTML', $disable_web_preview = true, $disable_notification = false) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'text' => &$text,
            'reply_to_message_id' => &$message_id,
            'parse_mode' => $parse_mode,
            'reply_markup' => &$inline_keyboard,
            'disable_web_page_preview' => $disable_web_preview,
            'disable_notification' => $disable_notification
        ];
        $url = $this->api_url . 'sendMessage?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &getChatRef(&$chat_id) {
        $parameters = [
            'chat_id' => &$chat_id,
        ];
        $url = $this->api_url . 'getChat?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    /*
     * Answer a callback_query, this function remove the updating cirle on an inline keyboard button
     * without showing any message to the user (https://core.telegram.org/bots/api#answercallbackquery)
     */
    public function &answerEmptyCallbackQuery() {
        $parameters = [
            'callback_query_id' => &$this->update['callback_query']['id'],
            'text' => ''
        ];
        $url = $this->api_url . 'answerCallbackQuery?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &answerCallbackQueryRef(&$text, $show_alert = false) {
        $parameters = [
            'callback_query_id' => &$this->update['callback_query']['id'],
            'text' => &$text,
            'show_alert' => &$show_alert
        ];
        $url = $this->api_url . 'answerCallbackQuery?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &editMessageTextRef(&$text, &$message_id, $parse_mode = 'HTML', $disable_web_preview = false) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'message_id' => &$message_id,
            'text' => &$text,
            'parse_mode' => &$parse_mode,
            'disable_web_page_preview' => &$disable_web_preview,
        ];
        $url = $this->api_url . 'editMessageText?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &editInlineMessageTextRef(&$text, &$inline_message_id, $parse_mode = 'HTML', $disable_web_preview = false) {
        $parameters = [
            'inline_message_id' => &$inline_message_id,
            'text' => &$text,
            'parse_mode' => &$parse_mode,
            'disable_web_page_preview' => &$disable_web_preview,
        ];
        $url = $this->api_url . 'editMessageText?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

     /*
     * Edit text and inline keyboard of a message(https://core.telegram.org/bots/api#editmessagetext)
     * @param
     * $text New text of the message
     * $message_id Identifier of the message to edit
     * $inline_keyboard Inlike keyboard array (https://core.telegram.org/bots/api#inlinekeyboardmarkup)
     */
    public function &editMessageTextKeyboard(&$text, &$inline_keyboard, &$message_id, $parse_mode = 'HTML', $disable_web_preview = false) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'message_id' => &$message_id,
            'text' => &$text,
            'reply_markup' => &$inline_keyboard,
            'parse_mode' => &$parse_mode,
            'disable_web_page_preview' => &$disable_web_preview,
        ];
        $url = $this->api_url . 'editMessageText?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    /*
     * Edit text and inline keyboard of a message sent by inline query (https://core.telegram.org/bots/api#editmessagetext)
     * @param
     * $text New text of the message
     * $message_id Identifier of the message to edit
     */

    public function &editInlineMessageTextKeyboard(&$text, &$inline_keyboard, &$inline_message_id, $parse_mode = 'HTML', $disable_web_preview = false) {
        $parameters = [
            'inline_message_id' => &$inline_message_id,
            'text' => &$text,
            'reply_markup' => &$inline_keyboard,
            'parse_mode' => &$parse_mode,
            'disable_web_page_preview' => &$disable_web_preview,
        ];
        $url = $this->api_url . 'editMessageText?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &editMessageReplyMarkupRef(&$message_id, &$inline_keyboard) {
        $parameters = [
            'chat_id' => &$this->chat_id,
            'message_id' => &$message_id,
            'reply_markup' => &$inline_keyboard,
        ];
        $url = $this->api_url . 'editMessageReplyMarkup?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    /*
     * Edit only the inline keyboard of a message sent by the inline query (https://core.telegram.org/bots/api#editmessagereplymarkup)
     * @param
     * $message_id Identifier of the message to edit
     * $inline_keyboard Inlike keyboard array (https://core.telegram.org/bots/api#inlinekeyboardmarkup)
     */
    public function &editInlineMessageReplyMarkup($message_id, $inline_keyboard) {
        $parameters = [
            'inline_message_id' => &$message_id,
            'reply_markup' => &$inline_keyboard,
        ];
        $url = $this->api_url . 'editMessageReplyMarkup?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &editInlineMessageReplyMarkupRef(&$message_id, &$inline_keyboard) {
        $parameters = [
            'inline_message_id' => &$message_id,
            'reply_markup' => &$inline_keyboard,
        ];
        $url = $this->api_url . 'editMessageReplyMarkup?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    /*
     * Answer a inline query (when the user write @botusername "Query") with a button, that will make user switch to the private chat with the bot, on the top of the results (https://core.telegram.org/bots/api#answerinlinequery)
     * without showing any results to the user
     * @param
     * $switch_pm_text Text to show on the button
     */
    public function &answerEmptyInlineQuerySwitchPM($switch_pm_text, $switch_pm_parameter = '', $is_personal = true, $cache_time = 300) {
        $parameters = [
            'inline_query_id' => &$this->update['inline_query']['id'],
            'switch_pm_text' => &$switch_pm_text,
            'is_personal' => $is_personal,
            'switch_pm_parameter' => $switch_pm_parameter,
            'cache_time' => $cache_time
        ];

        $url = $this->api_url . 'answerInlineQuery?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }

    public function &answerInlineQuerySwitchPMRef(&$results, &$switch_pm_text, $switch_pm_parameter = '', $is_personal = true, $cache_time = 300) {
        $parameters = [
            'inline_query_id' => &$this->update['inline_query']['id'],
            'switch_pm_text' => &$switch_pm_text,
            'is_personal' => $is_personal,
            'switch_pm_parameter' => &$switch_pm_parameter,
            'results' => &$results,
            'cache_time' => $cache_time
        ];

        $url = $this->api_url . 'answerInlineQuery?' . http_build_query($parameters);

        return $this->exec_curl_request($url);
    }
}
