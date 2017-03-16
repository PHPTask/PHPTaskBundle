<?php

namespace Task\TaskBundle\Command;

use Cron\CronExpression;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Task\Scheduler\TaskSchedulerInterface;
use Task\Storage\TaskExecutionRepositoryInterface;
use Task\TaskBundle\Builder\TaskBuilder;
use Task\TaskBundle\Entity\TaskRepository;
use Task\TaskInterface;

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
     * @var TaskRepository
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
     * @param TaskRepository $taskRepository
     * @param TaskExecutionRepositoryInterface $taskExecutionRepository
     */
    public function __construct(
        $name,
        array $systemTasks,
        TaskSchedulerInterface $scheduler,
        TaskRepository $taskRepository,
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
    protected function configure()
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
    protected function execute(InputInterface $input, OutputInterface $output)
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


        $output->writeln('');
        $output->writeln('System-tasks successfully scheduled');
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
            $this->disableTask($systemKey);

            $output->writeln(sprintf(' * System-task "%s" was <info>disabled</info>.', $systemKey));

            return;
        }

        if ($task = $this->taskRepository->findBySystemKey($systemKey)) {
            $this->updateTask($systemKey, $systemTask, $task);

            $output->writeln(sprintf(' * System-task "%s" was <info>updated</info>.', $systemKey));

            return;
        }

        /** @var TaskBuilder $builder */
        $builder = $this->scheduler->createTask($systemTask['handler_class'], $systemTask['workload']);
        $builder->setSystemKey($systemKey);
        if ($systemTask['cron_expression']) {
            $builder->cron($systemTask['cron_expression']);
        }

        $builder->schedule();

        $output->writeln(sprintf(' * System-task "%s" was <info>created</info>.', $systemKey));
    }

    /**
     * Disable task identified by system-key.
     *
     * @param string $systemKey
     */
    private function disableTask($systemKey)
    {
        if (!$task = $this->taskRepository->findBySystemKey($systemKey)) {
            return;
        }

        $task->setInterval($task->getInterval(), $task->getFirstExecution(), new \DateTime());
        $this->removePending($task);
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

        $this->removePending($task);
        $this->scheduler->scheduleTasks();
    }

    /**
     * Remove pending execution for given task.
     *
     * @param TaskInterface $task
     */
    private function removePending(TaskInterface $task)
    {
        if (!$execution = $this->taskExecutionRepository->findPending($task)) {
            return;
        }

        $this->taskExecutionRepository->remove($execution);
    }
}
