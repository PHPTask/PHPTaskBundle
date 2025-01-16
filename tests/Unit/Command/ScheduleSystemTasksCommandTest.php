<?php

namespace Task\TaskBundle\Unit\Command;

use Cron\CronExpression;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Task\Execution\TaskExecutionInterface;
use Task\Scheduler\TaskSchedulerInterface;
use Task\Storage\TaskExecutionRepositoryInterface;
use Task\TaskBundle\Builder\TaskBuilder;
use Task\TaskBundle\Command\ScheduleSystemTasksCommand;
use Task\TaskBundle\Entity\SystemTaskRepositoryInterface;
use Task\TaskBundle\Entity\Task;
use Task\TaskBundle\Tests\Functional\TestHandler;
use Task\TaskStatus;

class ScheduleSystemTasksCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var TaskSchedulerInterface
     */
    private $scheduler;

    /**
     * @var SystemTaskRepositoryInterface
     */
    private $taskRepository;

    /**
     * @var TaskExecutionRepositoryInterface
     */
    private $taskExecutionRepository;

    protected function setUp(): void
    {
        $this->scheduler = $this->prophesize(TaskSchedulerInterface::class);
        $this->taskRepository = $this->prophesize(SystemTaskRepositoryInterface::class);
        $this->taskExecutionRepository = $this->prophesize(TaskExecutionRepositoryInterface::class);
    }

    /**
     * @param array $systemTasks
     *
     * @return ScheduleSystemTasksCommand
     */
    protected function createCommand(array $systemTasks)
    {
        return new ScheduleSystemTasksCommand(
            'task:schedule:system-tasks',
            $systemTasks,
            $this->scheduler->reveal(),
            $this->taskRepository->reveal(),
            $this->taskExecutionRepository->reveal()
        );
    }

    public function testExecute()
    {
        $command = $this->createCommand(
            [
                'testing' => [
                    'enabled' => true,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test',
                    'cron_expression' => '* * * * *',
                ],
            ]
        );

        $task = $this->prophesize(Task::class);
        $task->getSystemKey()->willReturn('testing');

        $taskBuilder = $this->prophesize(TaskBuilder::class);

        $this->taskRepository->findBySystemKey('testing')->willReturn(null);
        $this->taskRepository->findSystemTasks()->willReturn([$task->reveal()]);

        $this->scheduler->createTask(TestHandler::class, 'test')->shouldBeCalled()->willReturn($taskBuilder->reveal());

        $taskBuilder->setSystemKey('testing')->shouldBeCalled();
        $taskBuilder->cron('* * * * *')->shouldBeCalled();
        $taskBuilder->schedule()->shouldBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }

    public function testExecuteMultiple()
    {
        $command = $this->createCommand(
            [
                'testing-1' => [
                    'enabled' => true,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test-1',
                    'cron_expression' => '* * * * *',
                ],
                'testing-2' => [
                    'enabled' => true,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test-2',
                    'cron_expression' => '* * * * *',
                ],
            ]
        );

        $task1 = $this->prophesize(Task::class);
        $task1->getSystemKey()->willReturn('testing-1');
        $task2 = $this->prophesize(Task::class);
        $task2->getSystemKey()->willReturn('testing-2');

        $this->taskRepository->findBySystemKey('testing-1')->willReturn(null);
        $this->taskRepository->findBySystemKey('testing-2')->willReturn(null);
        $this->taskRepository->findSystemTasks()->willReturn([$task1->reveal(), $task2->reveal()]);

        $taskBuilder1 = $this->prophesize(TaskBuilder::class);
        $this->scheduler->createTask(TestHandler::class, 'test-1')->shouldBeCalled()->willReturn(
            $taskBuilder1->reveal()
        );
        $taskBuilder1->setSystemKey('testing-1')->shouldBeCalled();
        $taskBuilder1->cron('* * * * *')->shouldBeCalled();
        $taskBuilder1->schedule()->shouldBeCalled();

        $taskBuilder2 = $this->prophesize(TaskBuilder::class);
        $this->scheduler->createTask(TestHandler::class, 'test-2')->shouldBeCalled()->willReturn(
            $taskBuilder2->reveal()
        );
        $taskBuilder2->setSystemKey('testing-2')->shouldBeCalled();
        $taskBuilder2->cron('* * * * *')->shouldBeCalled();
        $taskBuilder2->schedule()->shouldBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }

    public function testExecuteDisable()
    {
        $command = $this->createCommand(
            [
                'testing' => [
                    'enabled' => false,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test',
                    'cron_expression' => '* * * * *',
                ],
            ]
        );

        $task = $this->prophesize(Task::class);
        $task->getInterval()->willReturn(CronExpression::factory('* * * * *'));
        $task->getFirstExecution()->willReturn(new \DateTime());
        $task->getSystemKey()->willReturn('testing');

        $task->setInterval(
            $task->reveal()->getInterval(),
            $task->reveal()->getFirstExecution(),
            Argument::that(
                function ($date) {
                    return $date <= new \DateTime('+1 Minute');
                }
            )
        )->shouldBeCalled();

        $this->taskRepository->findBySystemKey('testing')->willReturn($task->reveal());
        $this->taskRepository->findSystemTasks()->willReturn([$task->reveal()]);

        $execution = $this->prophesize(TaskExecutionInterface::class);
        $execution->setStatus(TaskStatus::ABORTED);

        $this->taskExecutionRepository->findPending($task->reveal())->willReturn($execution->reveal());
        $this->taskExecutionRepository->save($execution->reveal())->shouldBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }

    public function testExecuteUpdate()
    {
        $command = $this->createCommand(
            [
                'testing' => [
                    'enabled' => true,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test',
                    'cron_expression' => '* * * * *',
                ],
            ]
        );

        $task = $this->prophesize(Task::class);
        $task->getSystemKey()->willReturn('testing');
        $task->getHandlerClass()->willReturn(TestHandler::class);
        $task->getWorkload()->willReturn('test');
        $task->getInterval()->willReturn(CronExpression::factory('@daily'));
        $task->getFirstExecution()->willReturn(new \DateTime());

        $task->setInterval(CronExpression::factory('* * * * *'), $task->reveal()->getFirstExecution())->shouldBeCalled(
            );

        $this->taskRepository->findBySystemKey('testing')->willReturn($task->reveal());
        $this->taskRepository->findSystemTasks()->willReturn([$task->reveal()]);

        $execution = $this->prophesize(TaskExecutionInterface::class);
        $execution->setStatus(TaskStatus::ABORTED);

        $this->taskExecutionRepository->findPending($task->reveal())->willReturn($execution->reveal());
        $this->taskExecutionRepository->save($execution->reveal())->shouldBeCalled();

        $this->scheduler->scheduleTasks()->shouldBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }

    public function testExecuteUpdateNotSupported()
    {
        $command = $this->createCommand(
            [
                'testing' => [
                    'enabled' => true,
                    'handler_class' => TestHandler::class,
                    'workload' => 'test',
                    'cron_expression' => '* * * * *',
                ],
            ]
        );

        $task = $this->prophesize(Task::class);
        $task->getSystemKey()->willReturn('testing');
        $task->getHandlerClass()->willReturn('not-existing');
        $task->getWorkload()->willReturn('new-workload');
        $task->getInterval()->willReturn(CronExpression::factory('@daily'));
        $task->getFirstExecution()->willReturn(new \DateTime());

        $task->setInterval(Argument::cetera())->shouldNotBeCalled();

        $this->taskRepository->findBySystemKey('testing')->willReturn($task->reveal());
        $this->taskRepository->findSystemTasks()->willReturn([$task->reveal()]);

        $this->taskExecutionRepository->save(Argument::cetera())->shouldNotBeCalled();

        $this->scheduler->scheduleTasks()->shouldNotBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }

    public function testExecuteRemove()
    {
        $command = $this->createCommand([]);

        $task = $this->prophesize(Task::class);
        $task->getInterval()->willReturn(CronExpression::factory('* * * * *'));
        $task->getFirstExecution()->willReturn(new \DateTime());
        $task->getSystemKey()->willReturn('testing');

        $task->setInterval(
            $task->reveal()->getInterval(),
            $task->reveal()->getFirstExecution(),
            Argument::that(
                function ($date) {
                    return $date <= new \DateTime('+1 Minute');
                }
            )
        )->shouldBeCalled();

        $this->taskRepository->findBySystemKey('testing')->willReturn($task->reveal());
        $this->taskRepository->findSystemTasks()->willReturn([$task->reveal()]);

        $execution = $this->prophesize(TaskExecutionInterface::class);
        $execution->setStatus(TaskStatus::ABORTED);

        $this->taskExecutionRepository->findPending($task->reveal())->willReturn($execution->reveal());
        $this->taskExecutionRepository->save($execution->reveal())->shouldBeCalled();

        $command->run(
            new StringInput(''),
            $this->prophesize(OutputInterface::class)->reveal()
        );
    }
}
