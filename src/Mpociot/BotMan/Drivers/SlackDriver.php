<?php

namespace Mpociot\BotMan\Drivers;

use Mpociot\BotMan\Answer;
use Mpociot\BotMan\Message;
use Mpociot\BotMan\Question;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

class SlackDriver extends Driver
{
    /** @var Collection|ParameterBag */
    protected $payload;

    /** @var Collection */
    protected $event;

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        /*
         * If the request has a POST parameter called 'payload'
         * we're dealing with an interactive button response.
         */
        if (! is_null($request->get('payload'))) {
            $payloadData = json_decode($request->get('payload'), true);

            $this->payload = Collection::make($payloadData);
            $this->event = Collection::make([
                'channel' => $payloadData['channel']['id'],
                'user' => $payloadData['user']['id'],
            ]);
        } else {
            $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
            $this->event = Collection::make($this->payload->get('event'));
        }
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return ! is_null($this->event->get('user'));
    }

    /**
     * @param  Message $message
     * @return Answer
     */
    public function getConversationAnswer(Message $message)
    {
        if ($this->payload instanceof Collection) {
            return Answer::create($this->payload['actions'][0]['name'])
                ->setInteractiveReply(true)
                ->setValue($this->payload['actions'][0]['value'])
                ->setCallbackId($this->payload['callback_id']);
        }

        return Answer::create($this->event->get('text'));
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        $messageText = '';
        if (! $this->payload instanceof Collection && $this->isBot() === false) {
            $messageText = $this->event->get('text');
        }

        return [new Message($messageText, $this->event->get('user'), $this->event->get('channel'))];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return $this->event->has('bot_id');
    }

    /**
     * @param string|Question $message
     * @param Message $matchingMessage
     * @param array $additionalParameters
     * @return $this
     */
    public function reply($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = array_merge([
            'token' => $this->payload->get('token'),
            'channel' => $matchingMessage->getChannel(),
        ], $additionalParameters);
        /*
         * If we send a Question with buttons, ignore
         * the text and append the question.
         */
        if ($message instanceof Question) {
            $parameters['text'] = '';
            $parameters['attachments'] = json_encode([$message->toArray()]);
        } else {
            $parameters['text'] = $this->format($message);
        }

        $parameters['token'] = $this->config->get('slack_token');

        return $this->http->post('https://slack.com/api/chat.postMessage', [], $parameters);
    }

    /**
     * Formats a string for Slack.
     *
     * @param  string $string
     * @return string
     */
    private function format($string)
    {
        $string = str_replace('&', '&amp;', $string);
        $string = str_replace('<', '&lt;', $string);
        $string = str_replace('>', '&gt;', $string);

        return $string;
    }
}
