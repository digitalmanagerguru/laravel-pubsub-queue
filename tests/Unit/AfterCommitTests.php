<?php

namespace Digitalmanagerguru\PubSubQueue\Tests\Unit;

use Digitalmanagerguru\PubSubQueue\Connectors\PubSubConnector;
use Digitalmanagerguru\PubSubQueue\PubSubQueue;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Subscription;
use Google\Cloud\PubSub\Topic;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// O afterCommit (do job, do listener ou o after_commit da conexão) adia a publicação para o commit da
// transação, como nos drivers do Laravel. Antes o push/later/bulk publicavam na hora, dentro da transação.
#[AllowMockObjectsWithoutExpectations]
final class AfterCommitTests extends TestCase
{
    private Topic $topic;

    private DatabaseTransactionsManager $transactions;

    private array $published = [];

    protected function setUp(): void
    {
        $this->topic = $this->createMock(Topic::class);
        $this->topic->method('publish')->willReturnCallback(function (array $message) {
            $this->published[] = $message;

            return ['messageIds' => ['m']];
        });
        $this->topic->method('publishBatch')->willReturnCallback(function (array $messages) {
            array_push($this->published, ...$messages);

            return ['messageIds' => array_fill(0, count($messages), 'm')];
        });

        $this->transactions = new DatabaseTransactionsManager;
    }

    private function queue(bool $dispatchAfterCommit = false, bool $withTransactions = true): PubSubQueue
    {
        $queue = $this->getMockBuilder(PubSubQueue::class)
            ->setConstructorArgs([$this->createMock(PubSubClient::class), 'default', 'subscriber', false, false, '', false, 1, $dispatchAfterCommit])
            ->onlyMethods(['getTopic', 'subscribeToTopic'])
            ->getMock();
        $queue->method('getTopic')->willReturn($this->topic);
        $queue->method('subscribeToTopic')->willReturn($this->createMock(Subscription::class));

        $container = new Container;

        if ($withTransactions) {
            $container->instance('db.transactions', $this->transactions);
        }

        $queue->setContainer($container);

        return $queue;
    }

    private function job(?bool $afterCommit, ?string $orderingKey = null): object
    {
        $job = new AfterCommitTestJob;

        if ($afterCommit !== null) {
            $job->afterCommit = $afterCommit;
        }

        if ($orderingKey !== null) {
            $job->orderingKey = $orderingKey;
        }

        return $job;
    }

    private function begin(): void
    {
        $this->transactions->begin('mysql', 1);
    }

    private function commit(): void
    {
        $this->transactions->commit('mysql', 1, 0);
    }

    private function rollback(): void
    {
        $this->transactions->rollback('mysql', 0);
    }

    public function testPushWithAfterCommitWaitsForTheCommit(): void
    {
        $queue = $this->queue();
        $this->begin();

        $queue->push($this->job(true, 'order-1'));

        $this->assertCount(0, $this->published);

        $this->commit();

        $this->assertCount(1, $this->published);
        $this->assertSame('order-1', $this->published[0]['orderingKey']);
    }

    public function testPushWithAfterCommitIsDroppedOnRollback(): void
    {
        $queue = $this->queue();
        $this->begin();

        $queue->push($this->job(true));
        $this->rollback();

        $this->assertCount(0, $this->published);
    }

    public function testPushWithoutAfterCommitPublishesInsideTheTransaction(): void
    {
        $queue = $this->queue();
        $this->begin();

        $queue->push($this->job(null));

        $this->assertCount(1, $this->published);
    }

    public function testPushWithAfterCommitAndNoOpenTransactionPublishesAtOnce(): void
    {
        $this->queue()->push($this->job(true));

        $this->assertCount(1, $this->published);
    }

    public function testPushWithAfterCommitAndNoTransactionManagerPublishesAtOnce(): void
    {
        $this->queue(withTransactions: false)->push($this->job(true));

        $this->assertCount(1, $this->published);
    }

    public function testLaterWithAfterCommitWaitsForTheCommitAndKeepsAvailableAt(): void
    {
        $queue = $this->queue();
        $this->begin();

        $queue->later(60, $this->job(true));

        $this->assertCount(0, $this->published);

        $this->commit();

        $this->assertCount(1, $this->published);
        $this->assertArrayHasKey('available_at', $this->published[0]['attributes']);
    }

    public function testConnectionAfterCommitDefersJobsWithoutTheirOwnSetting(): void
    {
        $queue = $this->queue(dispatchAfterCommit: true);
        $this->begin();

        $queue->push($this->job(null));
        $queue->push($this->job(false));

        $this->assertCount(1, $this->published, 'afterCommit = false no job vence o after_commit da conexão');

        $this->commit();

        $this->assertCount(2, $this->published);
    }

    public function testBulkDefersOnlyTheAfterCommitJobs(): void
    {
        $queue = $this->queue();
        $this->begin();

        $queue->bulk([$this->job(true), $this->job(null), $this->job(null)]);

        $this->assertCount(2, $this->published);

        $this->commit();

        $this->assertCount(3, $this->published);
    }

    public function testConnectorPassesAfterCommitFromConfig(): void
    {
        $queue = (new PubSubConnector)->connect(['project_id' => 'p', 'after_commit' => true]);

        $this->assertTrue((fn () => $this->dispatchAfterCommit)->call($queue));
    }
}

final class AfterCommitTestJob
{
    public ?bool $afterCommit = null;

    public ?string $orderingKey = null;

    public function handle(): void {}
}
