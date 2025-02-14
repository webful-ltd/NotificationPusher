<?php

/*
 * This file is part of NotificationPusher.
 *
 * (c) 2013 Cédric Dugat <cedric@dugat.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sly\NotificationPusher\Adapter;

use Sly\NotificationPusher\Collection\DeviceCollection;
use Sly\NotificationPusher\Exception\AdapterException;
use Sly\NotificationPusher\Exception\PushException;
use Sly\NotificationPusher\Model\BaseOptionedModel;
use Sly\NotificationPusher\Model\DeviceInterface;
use Sly\NotificationPusher\Model\MessageInterface;
use Sly\NotificationPusher\Model\PushInterface;
use ZendService\Apple\Apns\Client\AbstractClient as ServiceAbstractClient;
use ZendService\Apple\Apns\Client\Feedback as ServiceFeedbackClient;
use ZendService\Apple\Apns\Client\Message as ServiceClient;
use ZendService\Apple\Apns\Message as ServiceMessage;
use ZendService\Apple\Apns\Message\Alert as ServiceAlert;
use ZendService\Apple\Apns\Response\Feedback;
use ZendService\Apple\Apns\Response\Message as ServiceResponse;

/**
 * @uses \Sly\NotificationPusher\Adapter\BaseAdapter
 *
 * @author Cédric Dugat <cedric@dugat.me>
 */
class Apns extends BaseAdapter implements FeedbackAdapterInterface
{

    /**
     * @var ServiceClient
     */
    private $openedClient;

    /**
     * @var ServiceFeedbackClient
     */
    private $feedbackClient;

    /**
     * {@inheritdoc}
     *
     * @throws AdapterException
     */
    public function __construct(array $parameters = [])
    {
        parent::__construct($parameters);

        $cert = $this->getParameter('certificate');

        if (false === file_exists($cert)) {
            throw new AdapterException(sprintf('Certificate %s does not exist', $cert));
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws PushException
     */
    public function push(PushInterface $push)
    {
        $client = $this->getOpenedServiceClient();

        $pushedDevices = new DeviceCollection();

        foreach ($push->getDevices() as $device) {
            $message = $this->getServiceMessageFromOrigin($device, $push->getMessage());

            try {
                /** @var ServiceResponse $response */
                $response = $client->send($message);

                $responseArr = [
                    'id' => $response->getId(),
                    'token' => $response->getCode(),
                ];
                $push->addResponse($device, $responseArr);

                if (ServiceResponse::RESULT_OK === $response->getCode()) {
                    $pushedDevices->add($device);
                } else {
                    $client->close();
                    unset($this->openedClient, $client);
                    // Assign returned new client to the in-scope/in-use $client variable
                    $client = $this->getOpenedServiceClient();
                }

                $this->response->addOriginalResponse($device, $response);
                $this->response->addParsedResponse($device, $responseArr);
            } catch (\RuntimeException $e) {
                throw new PushException($e->getMessage());
            }
        }

        return $pushedDevices;
    }

    /**
     * @return array
     */
    public function getFeedback()
    {
        $client = $this->getOpenedFeedbackClient();
        $responses = [];
        $serviceResponses = $client->feedback();

        /** @var Feedback $response */
        foreach ($serviceResponses as $response) {
            $responses[$response->getToken()] = new \DateTime(date('c', $response->getTime()));
        }

        return $responses;
    }

    /**
     * @param ServiceAbstractClient|null $client Client
     *
     * @return ServiceAbstractClient
     */
    public function getOpenedClient(ServiceAbstractClient $client = null)
    {
        if (!$client) {
            $client = new ServiceClient();
        }

        $client->open(
            $this->isProductionEnvironment() ? ServiceClient::PRODUCTION_URI : ServiceClient::SANDBOX_URI,
            $this->getParameter('certificate'),
            $this->getParameter('passPhrase')
        );

        return $client;
    }

    /**
     * @return ServiceClient
     */
    protected function getOpenedServiceClient()
    {
        if (!isset($this->openedClient)) {
            $this->openedClient = $this->getOpenedClient(new ServiceClient());
        }

        return $this->openedClient;
    }

    /**
     * @return ServiceFeedbackClient
     */
    private function getOpenedFeedbackClient()
    {
        if (!isset($this->feedbackClient)) {
            $this->feedbackClient = $this->getOpenedClient(new ServiceFeedbackClient());
        }

        return $this->feedbackClient;
    }

    /**
     * @param DeviceInterface $device Device
     * @param BaseOptionedModel|MessageInterface $message Message
     *
     * @return ServiceMessage
     */
    public function getServiceMessageFromOrigin(DeviceInterface $device, BaseOptionedModel $message)
    {
        $badge = ($message->hasOption('badge'))
            ? (int) ($message->getOption('badge') + $device->getParameter('badge', 0))
            : false;

        $sound = $message->getOption('sound');
        $contentAvailable = $message->getOption('content-available');
        $mutableContent = $message->getOption('mutable-content');
        $category = $message->getOption('category');
        $urlArgs = $message->getOption('urlArgs');
        $expire = $message->getOption('expire');

        $alert = new ServiceAlert(
            $message->getText(),
            $message->getOption('actionLocKey'),
            $message->getOption('locKey'),
            $message->getOption('locArgs'),
            $message->getOption('launchImage'),
            $message->getOption('title'),
            $message->getOption('titleLocKey'),
            $message->getOption('titleLocArgs')
        );
        if ($actionLocKey = $message->getOption('actionLocKey')) {
            $alert->setActionLocKey($actionLocKey);
        }
        if ($locKey = $message->getOption('locKey')) {
            $alert->setLocKey($locKey);
        }
        if ($locArgs = $message->getOption('locArgs')) {
            $alert->setLocArgs($locArgs);
        }
        if ($launchImage = $message->getOption('launchImage')) {
            $alert->setLaunchImage($launchImage);
        }
        if ($title = $message->getOption('title')) {
            $alert->setTitle($title);
        }
        if ($titleLocKey = $message->getOption('titleLocKey')) {
            $alert->setTitleLocKey($titleLocKey);
        }
        if ($titleLocArgs = $message->getOption('titleLocArgs')) {
            $alert->setTitleLocArgs($titleLocArgs);
        }

        $serviceMessage = new ServiceMessage();
        $serviceMessage->setId(sha1($device->getToken() . $message->getText()));
        $serviceMessage->setBundleId($this->getParameter('bundleId'));
        $serviceMessage->setAlert($alert);
        $serviceMessage->setToken($device->getToken());
        if (false !== $badge) {
            $serviceMessage->setBadge($badge);
        }
        $serviceMessage->setCustom($message->getOption('custom', []));

        if (null !== $sound) {
            $serviceMessage->setSound($sound);
        }

        if (null !== $contentAvailable) {
            $serviceMessage->setContentAvailable($contentAvailable);
        }

        if (null !== $mutableContent) {
            $serviceMessage->setMutableContent($mutableContent);
        }

        if (null !== $category) {
            $serviceMessage->setCategory($category);
        }

        if (null !== $urlArgs) {
            $serviceMessage->setUrlArgs($urlArgs);
        }

        if (null !== $expire) {
            $serviceMessage->setExpire($expire);
        }

        return $serviceMessage;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($token)
    {
        return ctype_xdigit($token);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedParameters()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultParameters()
    {
        return ['passPhrase' => null];
    }

    /**
     * {@inheritdoc}
     */
    public function getRequiredParameters()
    {
        return ['certificate', 'bundleId'];
    }
}
