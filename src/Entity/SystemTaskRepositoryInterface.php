<?php

declare(strict_types=1);

namespace Task\TaskBundle\Entity;

use Task\Storage\TaskRepositoryInterface;

interface SystemTaskRepositoryInterface extends TaskRepositoryInterface
{
    /**
     * Returns all system-task.
     *
     * @return TaskInterface[]
     */
    public function findSystemTasks();

    /**
     * Returns task identified by system-key.
     *
     * @param string $systemKey
     *
     * @return TaskInterface
     */
    public function findBySystemKey($systemKey);
}
