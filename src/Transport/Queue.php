<?php

namespace Abau\MessengerAzureQueueTransport\Transport;

use MicrosoftAzure\Storage\Common\Internal\StorageServiceSettings;
use MicrosoftAzure\Storage\Queue\Models\CreateMessageOptions;
use MicrosoftAzure\Storage\Queue\Models\ListMessagesOptions;
use MicrosoftAzure\Storage\Queue\Models\QueueMessage;
use MicrosoftAzure\Storage\Queue\QueueRestProxy;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

/**
 * Class Queue
 */
class Queue
{
    /**
     * @var string
     */
    private $dsn;

    /**
     * @var array
     */
    private $options;

    /**
     * @var QueueRestProxy
     */
    private $client;

    /**
     * Queue constructor.
     *
     * @param string $dsn
     * @param array $options
     */
    public function __construct(string $dsn, array $options)
    {
        $this->dsn = $dsn;
        $this->options = $options;

        $this->client = $this->createClient();
    }

    public function create(): void
    {
        $name = $this->getOption('queue_name');
        if (empty($name)) {
            throw new \RuntimeException('Could not setup queue with empty name. Configure queue_name option to fix this error!');
        }

        $this->client->createQueue($name);
    }

    /**
     * Reads messages from the queue.
     *
     * @return Message[]
     */
    public function get(): array
    {
        $options = new ListMessagesOptions();
        $options->setVisibilityTimeoutInSeconds($this->getOption('visibility_timeout'));
        $options->setNumberOfMessages($this->getOption('results_limit', 1));

        $list = $this->client->listMessages($this->getOption('queue_name'), $options);

        $list = $list->getQueueMessages();

        return array_map(function (QueueMessage $queueMessage) {

            $message = $this->decodeMessage($queueMessage->getMessageText());

            $message->setOriginal($queueMessage);

            return $message;

        }, $list);
    }

    /**
     * Sends message to queue.
     *
     * @param Message $message
     * @return Message
     */
    public function send(Message $message): Message
    {
        $options = new CreateMessageOptions();
        $options->setTimeToLiveInSeconds($this->getOption('time_to_live'));

        $content = $this->encodeMessage($message);

        $result = $this->client->createMessage($this->getOption('queue_name'), $content, $options);

        $message->setOriginal($result->getQueueMessage());

        return $message;
    }

    /**
     * Deletes message from queue.
     *
     * @param Message|string $messageId The message object or the messageId
     * @param string|null $popReceipt
     */
    public function delete($messageId, string $popReceipt = null): void
    {
        if ($messageId instanceof Message) {

            $original = $messageId->getOriginal();

            if (!$original) {

                throw new \InvalidArgumentException('Cannot delete, missing original queue message attribute.');
            }

            $messageId = $original->getMessageId();
            $popReceipt = $original->getPopReceipt();
        }

        if (!$popReceipt) {

            throw new \InvalidArgumentException('Cannot delete, missing pop receipt.');
        }

        $this->client->deleteMessage($this->getOption('queue_name'), $messageId, $popReceipt);
    }

    /**
     * Creates Azure Storage Queue client.
     *
     * @return QueueRestProxy
     */
    private function createClient(): QueueRestProxy
    {
        $endpoint = $this->getConnectionString($this->dsn);

        return $this->client = QueueRestProxy::createQueueService($endpoint);
    }

    /**
     * Retrieves the endpoint connection string.
     *
     * @param string $dsn
     * @return string
     */
    private function getConnectionString(string $dsn): string
    {
        $connection = 'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s';
        $schema = rawurldecode(parse_url($dsn, PHP_URL_SCHEME));

        if ($schema === 'azurequeue-connection-string') {
            return str_replace("{$schema}://", '', $dsn);
        }

        $name = rawurldecode(parse_url($dsn, PHP_URL_USER));
        $key = rawurldecode(parse_url($dsn, PHP_URL_PASS));

        return sprintf($connection, $name, $key);
    }

    /**
     * Retrieves option.
     *
     * @param $name
     * @param null $default
     * @return mixed|null
     */
    private function getOption($name, $default = null)
    {
        if (!array_key_exists($name, $this->options)) {
            return $default;
        }

        return $this->options[$name];
    }

    /**
     * Encodes the message.
     *
     * @param Message $message
     * @return string
     */
    private function encodeMessage(Message $message): string
    {
        if ($this->getOption('body_only') === true) {
            $jsonBody = $message->getBody();
            $data = json_decode($jsonBody, true);
            if ($data === false) {
                throw new MessageDecodingFailedException('Failed to decode message for transport');
            }

            $data['__headers'] = $message->getHeaders();
            $combindJson = json_encode($data);
            if ($combindJson === false) {
                throw new MessageDecodingFailedException('Failed to reencode message for transport');
            }

            return base64_encode($combindJson);
        }

        return base64_encode(serialize($message));
    }

    /**
     * Decodes the message.
     *
     * @param string $message
     * @return Message
     */
    private function decodeMessage(string $message): Message
    {
        if ($this->getOption('body_only') === true) {
            $json = base64_decode($message);
            $data = json_decode($json, true);
            if ($data === false) {
                throw new MessageDecodingFailedException('Failed to decode message from transport');
            }

            $headers = [];
            if (array_key_exists('__headers', $data)) {
                $headers = $data['__headers'];
                unset($data['__headers']);
                $json = json_encode($data);
                if ($json === false) {
                    throw new MessageDecodingFailedException('Failed to reencode message from transport');
                }
            }

            $result = new Message($json, $headers);
            return $result;
        }

        return unserialize(base64_decode($message));
    }
}
