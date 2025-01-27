<?php declare(strict_types = 1);

namespace MailPoet\Segments\DynamicSegments;

use MailPoet\Entities\DynamicSegmentFilterData;
use MailPoet\Entities\DynamicSegmentFilterEntity;
use MailPoet\Entities\NewsletterEntity;
use MailPoet\Entities\ScheduledTaskEntity;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SendingQueueEntity;
use MailPoet\Entities\StatisticsOpenEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Segments\DynamicSegments\Filters\EmailAction;
use MailPoet\Segments\DynamicSegments\Filters\SubscriberSubscribedDate;
use MailPoet\Segments\DynamicSegments\Filters\UserRole;
use MailPoet\Subscribers\SubscribersRepository;
use MailPoet\WP\Functions;
use MailPoetVendor\Carbon\Carbon;
use MailPoetVendor\Doctrine\DBAL\Driver\Statement;

class FilterHandlerTest extends \MailPoetTest {

  /** @var FilterHandler */
  private $filterHandler;

  /** @var SubscriberEntity */
  private $subscriber1;

  /** @var SubscriberEntity */
  private $subscriber2;

  public function _before() {
    $this->cleanWpUsers();
    $this->filterHandler = $this->diContainer->get(FilterHandler::class);
    $this->tester->createWordPressUser('user-role-test1@example.com', 'editor');
    $this->tester->createWordPressUser('user-role-test2@example.com', 'administrator');
    $this->tester->createWordPressUser('user-role-test3@example.com', 'editor');

    // fetch entities
    /** @var SubscribersRepository $subscribersRepository */
    $subscribersRepository = $this->diContainer->get(SubscribersRepository::class);
    $subscriber1 = $subscribersRepository->findOneBy(['email' => 'user-role-test1@example.com']);
    assert($subscriber1 instanceof SubscriberEntity);
    $subscriber1->setStatus(SubscriberEntity::STATUS_SUBSCRIBED);
    $subscriber1->setLastSubscribedAt(new Carbon());
    $this->subscriber1 = $subscriber1;
    $subscriber2 = $subscribersRepository->findOneBy(['email' => 'user-role-test2@example.com']);
    assert($subscriber2 instanceof SubscriberEntity);
    $subscriber2->setStatus(SubscriberEntity::STATUS_SUBSCRIBED);
    $subscriber2->setLastSubscribedAt(new Carbon());
    $this->subscriber2 = $subscriber2;
    $subscriber3 = $subscribersRepository->findOneBy(['email' => 'user-role-test3@example.com']);
    assert($subscriber3 instanceof SubscriberEntity);
    $subscriber3->setStatus(SubscriberEntity::STATUS_SUBSCRIBED);
    $subscriber3->setLastSubscribedAt(new Carbon());
    $this->entityManager->flush();
  }

  public function testItAppliesFilter() {
    $segment = $this->getSegment('editor');
    $statement = $this->filterHandler->apply($this->getQueryBuilder(), $segment)->execute();
    assert($statement instanceof Statement);
    $result = $statement->fetchAll();
    expect($result)->count(2);
    $subscriber1 = $this->entityManager->find(SubscriberEntity::class, $result[0]['id']);
    assert($subscriber1 instanceof SubscriberEntity);
    $subscriber2 = $this->entityManager->find(SubscriberEntity::class, $result[1]['id']);
    assert($subscriber2 instanceof SubscriberEntity);
    expect($subscriber1->getEmail())->equals('user-role-test1@example.com');
    expect($subscriber2->getEmail())->equals('user-role-test3@example.com');
  }

  public function testItAppliesTwoFiltersWithoutSpecifyingConnection() {
    $wp = $this->diContainer->get(Functions::class);
    $segment = $this->getSegment('editor');
    $filterData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_USER_ROLE, SubscriberSubscribedDate::TYPE, [
      'operator' => SubscriberSubscribedDate::IN_THE_LAST,
      'value' => 30,
    ]);
    $dynamicSegmentFilter = new DynamicSegmentFilterEntity($segment, $filterData);
    $this->entityManager->persist($dynamicSegmentFilter);
    $segment->addDynamicFilter($dynamicSegmentFilter);
    $this->entityManager->flush();
    $statement = $this->filterHandler->apply($this->getQueryBuilder(), $segment)->execute();
    assert($statement instanceof Statement);
    $result = $statement->fetchAll();
    expect($result)->count(2);
  }

  public function testItAppliesTwoFiltersWithOr() {
    $segment = new SegmentEntity('Dynamic Segment', SegmentEntity::TYPE_DYNAMIC, 'description');
    $this->entityManager->persist($segment);
    $filterData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_USER_ROLE, UserRole::TYPE, [
      'wordpressRole' => 'administrator',
      'connect' => 'or',
    ]);
    $dynamicSegmentFilter = new DynamicSegmentFilterEntity($segment, $filterData);
    $this->entityManager->persist($dynamicSegmentFilter);
    $segment->addDynamicFilter($dynamicSegmentFilter);
    $filterData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_USER_ROLE, UserRole::TYPE, [
      'wordpressRole' => 'editor',
      'connect' => 'or',
    ]);
    $dynamicSegmentFilter = new DynamicSegmentFilterEntity($segment, $filterData);
    $this->entityManager->persist($dynamicSegmentFilter);
    $segment->addDynamicFilter($dynamicSegmentFilter);
    $this->entityManager->flush();
    $statement = $this->filterHandler->apply($this->getQueryBuilder(), $segment)->execute();
    assert($statement instanceof Statement);
    $result = $statement->fetchAll();
    expect($result)->count(3);
  }

  public function testItAppliesTwoFiltersWithAnd() {
    $segment = new SegmentEntity('Dynamic Segment', SegmentEntity::TYPE_DYNAMIC, 'description');
    $this->entityManager->persist($segment);
    // filter user is an editor
    $editorData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_USER_ROLE, UserRole::TYPE, [
      'wordpressRole' => 'editor',
      'connect' => 'and',
    ]);
    $filterEditor = new DynamicSegmentFilterEntity($segment, $editorData);
    $this->entityManager->persist($filterEditor);
    $segment->addDynamicFilter($filterEditor);
    // filter user opened an email
    $newsletter = new NewsletterEntity();
    $task = new ScheduledTaskEntity();
    $this->entityManager->persist($task);
    $queue = new SendingQueueEntity();
    $queue->setNewsletter($newsletter);
    $queue->setTask($task);
    $this->entityManager->persist($queue);
    $newsletter->getQueues()->add($queue);
    $newsletter->setSubject('newsletter 1');
    $newsletter->setStatus('sent');
    $newsletter->setType(NewsletterEntity::TYPE_STANDARD);
    $this->entityManager->persist($newsletter);
    $open = new StatisticsOpenEntity($newsletter, $queue, $this->subscriber1);
    $this->entityManager->persist($open);
    $open = new StatisticsOpenEntity($newsletter, $queue, $this->subscriber2);
    $this->entityManager->persist($open);
    $this->entityManager->flush();

    $openedData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_EMAIL, EmailAction::ACTION_OPENED, [
      'newsletter_id' => $newsletter->getId(),
      'connect' => 'and',
    ]);
    $filterOpened = new DynamicSegmentFilterEntity($segment, $openedData);
    $this->entityManager->persist($filterOpened);
    $segment->addDynamicFilter($filterOpened);
    $this->entityManager->flush();

    $statement = $this->filterHandler->apply($this->getQueryBuilder(), $segment)->execute();
    assert($statement instanceof Statement);
    $result = $statement->fetchAll();
    expect($result)->count(1);
  }

  private function getSegment(string $role): SegmentEntity {
    $filterData = new DynamicSegmentFilterData(DynamicSegmentFilterData::TYPE_USER_ROLE, UserRole::TYPE, [
      'wordpressRole' => $role,
    ]);
    $segment = new SegmentEntity('Dynamic Segment', SegmentEntity::TYPE_DYNAMIC, 'description');
    $dynamicSegmentFilter = new DynamicSegmentFilterEntity($segment, $filterData);
    $segment->addDynamicFilter($dynamicSegmentFilter);
    $this->entityManager->persist($segment);
    $this->entityManager->persist($dynamicSegmentFilter);
    $this->entityManager->flush();
    return $segment;
  }

  private function getQueryBuilder() {
    $subscribersTable = $this->entityManager->getClassMetadata(SubscriberEntity::class)->getTableName();
    return $this->entityManager
      ->getConnection()
      ->createQueryBuilder()
      ->select("$subscribersTable.id")
      ->from($subscribersTable);
  }

  public function _after() {
    $this->cleanWpUsers();
    $this->truncateEntity(SubscriberEntity::class);
    $this->truncateEntity(SegmentEntity::class);
    $this->truncateEntity(DynamicSegmentFilterEntity::class);
    $this->truncateEntity(NewsletterEntity::class);
    $this->truncateEntity(StatisticsOpenEntity::class);
    $this->truncateEntity(SendingQueueEntity::class);
    $this->truncateEntity(ScheduledTaskEntity::class);
  }

  private function cleanWpUsers() {
    $emails = ['user-role-test1@example.com', 'user-role-test2@example.com', 'user-role-test3@example.com'];
    foreach ($emails as $email) {
      $this->tester->deleteWordPressUser($email);
    }
  }
}
