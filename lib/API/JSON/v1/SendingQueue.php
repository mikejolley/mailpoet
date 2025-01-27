<?php

namespace MailPoet\API\JSON\v1;

use MailPoet\API\JSON\Endpoint as APIEndpoint;
use MailPoet\API\JSON\Error as APIError;
use MailPoet\API\JSON\Response;
use MailPoet\Config\AccessControl;
use MailPoet\Cron\Triggers\WordPress;
use MailPoet\Entities\NewsletterEntity;
use MailPoet\Models\Newsletter;
use MailPoet\Models\SendingQueue as SendingQueueModel;
use MailPoet\Newsletter\NewslettersRepository;
use MailPoet\Newsletter\Scheduler\Scheduler;
use MailPoet\Segments\SubscribersFinder;
use MailPoet\Services\Bridge;
use MailPoet\Tasks\Sending as SendingTask;
use MailPoet\Util\License\Features\Subscribers as SubscribersFeature;

class SendingQueue extends APIEndpoint {
  public $permissions = [
    'global' => AccessControl::PERMISSION_MANAGE_EMAILS,
  ];

  /** @var SubscribersFeature */
  private $subscribersFeature;

  /** @var SubscribersFinder */
  private $subscribersFinder;

  /** @var NewslettersRepository */
  private $newsletterRepository;

  /** @var Bridge */
  private $bridge;

  public function __construct(
    SubscribersFeature $subscribersFeature,
    NewslettersRepository $newsletterRepository,
    Bridge $bridge,
    SubscribersFinder $subscribersFinder
  ) {
    $this->subscribersFeature = $subscribersFeature;
    $this->subscribersFinder = $subscribersFinder;
    $this->newsletterRepository = $newsletterRepository;
    $this->bridge = $bridge;
  }

  public function add($data = []) {
    if ($this->subscribersFeature->check()) {
      return $this->errorResponse([
        APIError::FORBIDDEN => __('Subscribers limit reached.', 'mailpoet'),
      ], [], Response::STATUS_FORBIDDEN);
    }
    $newsletterId = (isset($data['newsletter_id'])
      ? (int)$data['newsletter_id']
      : false
    );

    // check that the newsletter exists
    $newsletter = Newsletter::findOneWithOptions($newsletterId);

    if (!$newsletter instanceof Newsletter) {
      return $this->errorResponse([
        APIError::NOT_FOUND => __('This newsletter does not exist.', 'mailpoet'),
      ]);
    }
    $newsletterEntity = $this->newsletterRepository->findOneById($newsletter->id);
    if (!$newsletterEntity instanceof NewsletterEntity) {
      return $this->errorResponse([
        APIError::NOT_FOUND => __('This newsletter does not exist.', 'mailpoet'),
      ]);
    }

    $validationError = $this->validateNewsletter($newsletterEntity);
    if ($validationError) {
      return $this->errorResponse([
        APIError::BAD_REQUEST => $validationError,
      ]);
    }

    // check that the sending method has been configured properly
    try {
      $mailer = new \MailPoet\Mailer\Mailer();
      $mailer->init();
    } catch (\Exception $e) {
      return $this->errorResponse([
        $e->getCode() => $e->getMessage(),
      ]);
    }

    // add newsletter to the sending queue
    $queue = SendingQueueModel::joinWithTasks()
      ->where('queues.newsletter_id', $newsletterEntity->getId())
      ->whereNull('tasks.status')
      ->findOne();

    if (!empty($queue)) {
      return $this->errorResponse([
        APIError::NOT_FOUND => __('This newsletter is already being sent.', 'mailpoet'),
      ]);
    }

    $scheduledQueue = SendingQueueModel::joinWithTasks()
      ->where('queues.newsletter_id', $newsletterEntity->getId())
      ->where('tasks.status', SendingQueueModel::STATUS_SCHEDULED)
      ->findOne();
    if ($scheduledQueue instanceof SendingQueueModel) {
      $queue = SendingTask::createFromQueue($scheduledQueue);
    } else {
      $queue = SendingTask::create();
      $queue->newsletterId = $newsletterEntity->getId();
    }

    WordPress::resetRunInterval();

    if ((bool)$newsletter->isScheduled) {
      // set newsletter status
      $newsletter->setStatus(Newsletter::STATUS_SCHEDULED);

      // set queue status
      $queue->status = SendingQueueModel::STATUS_SCHEDULED;
      $queue->scheduledAt = Scheduler::formatDatetimeString($newsletter->scheduledAt);
    } else {
      $segments = $newsletter->segments()->findMany();
      $subscribersCount = $this->subscribersFinder->addSubscribersToTaskFromSegments($queue->task(), $segments);
      if (!$subscribersCount) {
        return $this->errorResponse([
          APIError::UNKNOWN => __('There are no subscribers in that list!', 'mailpoet'),
        ]);
      }
      $queue->updateCount();
      $queue->status = null;
      $queue->scheduledAt = null;

      // set newsletter status
      $newsletter->setStatus(Newsletter::STATUS_SENDING);
    }
    $queue->save();

    $errors = $queue->getErrors();
    if (!empty($errors)) {
      return $this->errorResponse($errors);
    } else {
      return $this->successResponse(
        $newsletter->getQueue()->asArray()
      );
    }
  }

  private function validateNewsletter(NewsletterEntity $newsletterEntity): ?string {
    if (
      $newsletterEntity->getBody()
      && is_array($newsletterEntity->getBody())
      && $newsletterEntity->getBody()['content']
    ) {
      $body = json_encode($newsletterEntity->getBody()['content']);
      if ($body === false) {
        return __('Poet, please add prose to your masterpiece before you send it to your followers.');
      }

      if (
        $this->bridge->isMailpoetSendingServiceEnabled()
        && (strpos($body, '[link:subscription_unsubscribe_url]') === false)
        && (strpos($body, '[link:subscription_unsubscribe]') === false)
      ) {
        return __('All emails must include an "Unsubscribe" link. Add a footer widget to your email to continue.');
      }
    } else {
      return __('Poet, please add prose to your masterpiece before you send it to your followers.');
    }
    return null;
  }

  public function pause($data = []) {
    $newsletterId = (isset($data['newsletter_id'])
      ? (int)$data['newsletter_id']
      : false
    );
    $newsletter = Newsletter::findOne($newsletterId);

    if ($newsletter instanceof Newsletter) {
      $queue = $newsletter->getQueue();

      if ($queue === false) {
        return $this->errorResponse([
          APIError::UNKNOWN => __('This newsletter has not been sent yet.', 'mailpoet'),
        ]);
      } else {
        $queue->pause();
        return $this->successResponse(
          $newsletter->getQueue()->asArray()
        );
      }
    } else {
      return $this->errorResponse([
        APIError::NOT_FOUND => __('This newsletter does not exist.', 'mailpoet'),
      ]);
    }
  }

  public function resume($data = []) {
    if ($this->subscribersFeature->check()) {
      return $this->errorResponse([
        APIError::FORBIDDEN => __('Subscribers limit reached.', 'mailpoet'),
      ], [], Response::STATUS_FORBIDDEN);
    }
    $newsletterId = (isset($data['newsletter_id'])
      ? (int)$data['newsletter_id']
      : false
    );
    $newsletter = Newsletter::findOne($newsletterId);
    if ($newsletter instanceof Newsletter) {
      $queue = $newsletter->getQueue();

      if ($queue === false) {
        return $this->errorResponse([
          APIError::UNKNOWN => __('This newsletter has not been sent yet.', 'mailpoet'),
        ]);
      } else {
        $queue->resume();
        return $this->successResponse(
          $newsletter->getQueue()->asArray()
        );
      }
    } else {
      return $this->errorResponse([
        APIError::NOT_FOUND => __('This newsletter does not exist.', 'mailpoet'),
      ]);
    }
  }
}
