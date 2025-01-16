<?php

namespace Task\TaskBundle\Command;

use Cron\CronExpression;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Task\Scheduler\TaskSchedulerInterface;
use Task\Storage\TaskExecutionRepositoryInterface;
use Task\TaskBundle\Builder\TaskBuilder;
use Task\TaskBundle\Entity\SystemTaskRepositoryInterface;
use Task\TaskBundle\Entity\TaskRepository;
use Task\TaskInterface;
use Task\TaskStatus;

/**
 * Schedules configured system-tasks.
 */
class ScheduleSystemTasksCommand extends Command
{
    /**
     * @var array
     */
    private $systemTasks;

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

    /**
     * @param string $name
     * @param array $systemTasks
     * @param TaskSchedulerInterface $scheduler
     * @param SystemTaskRepositoryInterface $taskRepository
     * @param TaskExecutionRepositoryInterface $taskExecutionRepository
     */
    public function __construct(
        $name,
        array $systemTasks,
        TaskSchedulerInterface $scheduler,
        SystemTaskRepositoryInterface $taskRepository,
        TaskExecutionRepositoryInterface $taskExecutionRepository
    ) {
        parent::__construct($name);

        $this->systemTasks = $systemTasks;
        $this->scheduler = $scheduler;
        $this->taskRepository = $taskRepository;
        $this->taskExecutionRepository = $taskExecutionRepository;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Schedule system-tasks')->setHelp(
            <<<'EOT'
The <info>%command.name%</info> command schedules configured system tasks.

    $ %command.full_name%

You can configure them by extending the <info>task.system_task</info> array in your config file.
EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Schedule %s system-tasks:', count($this->systemTasks)));
        $output->writeln('');

        foreach ($this->systemTasks as $systemKey => $systemTask) {
            try {
                $this->processSystemTask($systemKey, $systemTask, $output);
            } catch (\Exception $exception) {
                $output->writeln(
                    sprintf(
                        ' * System-task "%s" failed because of: <exception>%s</exception>',
                        $systemKey,
                        $exception->getMessage()
                    )
                );
            }
        }

        foreach ($this->taskRepository->findSystemTasks() as $task) {
            if (!in_array($task->getSystemKey(), array_keys($this->systemTasks)) && ($this->disableTask($task))) {
                $output->writeln(
                    sprintf(' * System-task "%s" was <comment>disabled</comment>', $task->getSystemKey())
                );
            }
        }

        $output->writeln('');
        $output->writeln('System-tasks successfully scheduled');

        return 0;
    }

    /**
     * Process single system task.
     *
     * @param string $systemKey
     * @param array $systemTask
     * @param OutputInterface $output
     */
    private function processSystemTask($systemKey, array $systemTask, OutputInterface $output)
    {
        if (!$systemTask['enabled']) {
            if ($this->disableSystemTask($systemKey)) {
                $output->writeln(sprintf(' * System-task "%s" was <comment>disabled</comment>', $systemKey));
            }

            return;
        }

        if ($task = $this->taskRepository->findBySystemKey($systemKey)) {
            $this->updateTask($systemKey, $systemTask, $task);

            $output->writeln(sprintf(' * System-task "%s" was <info>updated</info>', $systemKey));

            return;
        }

        /** @var TaskBuilder $builder */
        $builder = $this->scheduler->createTask($systemTask['handler_class'], $systemTask['workload']);
        $builder->setSystemKey($systemKey);
        if ($systemTask['cron_expression']) {
            $builder->cron($systemTask['cron_expression']);
        }

        $builder->schedule();

        $output->writeln(sprintf(' * System-task "%s" was <info>created</info>', $systemKey));
    }

    /**
     * Disable task identified by system-key.
     *
     * @param string $systemKey
     *
     * @return bool
     */
    private function disableSystemTask($systemKey)
    {
        if (!$task = $this->taskRepository->findBySystemKey($systemKey)) {
            return false;
        }

        $this->disableTask($task);

        return true;
    }

    /**
     * Disable given task identified.
     *
     * @param TaskInterface $task
     *
     * @return bool
     */
    public function disableTask(TaskInterface $task)
    {
        $task->setInterval($task->getInterval(), $task->getFirstExecution(), new \DateTime());

        return $this->abortPending($task);
    }

    /**
     * Update given task.
     *
     * @param string $systemKey
     * @param array $systemTask
     * @param TaskInterface $task
     */
    private function updateTask($systemKey, array $systemTask, TaskInterface $task)
    {
        if ($task->getHandlerClass() !== $systemTask['handler_class']
            || $task->getWorkload() !== $systemTask['workload']
        ) {
            throw new \InvalidArgumentException(
                sprintf('No update of handle-class or workload is supported for system-task "%s".', $systemKey)
            );
        }

        if ($task->getInterval() === $systemTask['cron_expression']) {
            return;
        }

        $task->setInterval(CronExpression::factory($systemTask['cron_expression']), $task->getFirstExecution());

        $this->abortPending($task);
        $this->scheduler->scheduleTasks();
    }

    /**
     * Abort pending execution for given task.
     *
     * @param TaskInterface $task
     *
     * @return bool
     */
    private function abortPending(TaskInterface $task)
    {
        if (!$execution = $this->taskExecutionRepository->findPending($task)) {
            return false;
        }

        $execution->setStatus(TaskStatus::ABORTED);
        $this->taskExecutionRepository->save($execution);

        return true;
    }
}
