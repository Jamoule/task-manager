<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TaskService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getTasks(array $filters = [], ?string $sortBy = 'createdAt', string $order = 'ASC'): array
    {
        $this->logger->info('Fetching tasks', ['filters' => $filters, 'sortBy' => $sortBy, 'order' => $order]);

        // Basic filtering example (by status)
        $criteria = [];
        if (isset($filters['status'])) {
            $statusEnum = TaskStatus::tryFrom($filters['status']);
            if (null === $statusEnum) {
                $this->logger->warning('Invalid status filter value provided', ['status' => $filters['status']]);
                // Handle invalid status filter
                throw new \InvalidArgumentException("Invalid status value: {$filters['status']}");
            }
            $criteria['status'] = $statusEnum;
        }
        // TODO: Add more filters (priority, owner, date ranges etc.) - Note: Filter by User object now, not ID.

        // Basic sorting example
        $orderBy = [];
        if ($sortBy && in_array($sortBy, ['createdAt', 'dueAt', 'priority', 'title', 'position'])) {
            $direction = 'DESC' === strtoupper($order) ? 'DESC' : 'ASC';
            $orderBy[$sortBy] = $direction;
        } else {
            $sortBy = 'createdAt'; // Update sortBy for logging
            $orderBy[$sortBy] = 'ASC'; // Default sort
            $this->logger->info('Using default sorting', ['sortBy' => $sortBy, 'order' => 'ASC']);
        }

        $tasks = $this->taskRepository->findBy($criteria, $orderBy);
        $this->logger->info('Tasks fetched successfully', ['count' => count($tasks)]);

        return $tasks;
    }

    public function getTask(int $id): ?Task
    {
        $this->logger->info('Fetching task', ['id' => $id]);
        $task = $this->taskRepository->find($id);

        if (!$task) {
            $this->logger->notice('Task not found', ['id' => $id]);
        } else {
            $this->logger->info('Task found', ['id' => $id]);
        }

        return $task;
    }

    public function createTask(array $data, User $owner): Task
    {
        $this->logger->info('Attempting to create task', ['owner_id' => $owner->getId(), 'data' => $data]);

        $task = new Task();
        $task->setTitle($data['title']);
        $task->setDescription($data['description'] ?? null);
        $task->setOwner($owner);

        if (isset($data['dueAt'])) {
            try {
                $task->setDueAt(new \DateTimeImmutable($data['dueAt']));
            } catch (\Exception $e) {
                $this->logger->warning('Invalid date format for dueAt during task creation', ['dueAt' => $data['dueAt'], 'exception' => $e->getMessage()]);
                throw new \InvalidArgumentException("Invalid date format for dueAt: {$data['dueAt']}");
            }
        }
        if (isset($data['priority'])) {
            try {
                $task->setPriority(TaskPriority::from($data['priority']));
            } catch (\ValueError $e) {
                $this->logger->warning('Invalid priority value during task creation', ['priority' => $data['priority']]);
                throw new \InvalidArgumentException("Invalid priority value: {$data['priority']}");
            }
        }
        if (isset($data['status'])) {
            try {
                $task->setStatus(TaskStatus::from($data['status']));
            } catch (\ValueError $e) {
                $this->logger->warning('Invalid status value during task creation', ['status' => $data['status']]);
                throw new \InvalidArgumentException("Invalid status value: {$data['status']}");
            }
        }
        if (isset($data['position'])) {
            if (!is_numeric($data['position'])) {
                $this->logger->warning('Invalid position value during task creation', ['position' => $data['position']]);
                throw new \InvalidArgumentException('Invalid position value: must be a number.');
            }
            $task->setPosition((int) $data['position']);
        }

        // TODO: Handle tags if necessary

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->logger->info('Task created successfully', ['task_id' => $task->getId(), 'owner_id' => $owner->getId()]);

        return $task;
    }

    public function updateTask(int $id, array $data, bool $isPut = false): ?Task
    {
        $this->logger->info('Attempting to update task', ['id' => $id, 'data' => $data, 'isPut' => $isPut]);
        $task = $this->taskRepository->find($id);
        if (!$task) {
            $this->logger->warning('Task not found for update', ['id' => $id]);

            return null; // Or throw an exception
        }

        // Keep track if any change was made
        $updated = false;

        // Handle fields present in data
        if (array_key_exists('title', $data)) {
            if (empty($data['title'])) { // Add basic validation
                $this->logger->warning('Attempted to update task with empty title', ['id' => $id]);
                throw new \InvalidArgumentException('Title cannot be empty.');
            }
            if ($task->getTitle() !== $data['title']) {
                $task->setTitle($data['title']);
                $updated = true;
            }
        } elseif ($isPut) {
            $this->logger->warning('Missing required field: title for PUT request', ['id' => $id]);
            throw new \InvalidArgumentException('Missing required field: title for PUT request.');
        }

        if (array_key_exists('description', $data)) {
            if ($task->getDescription() !== $data['description']) {
                $task->setDescription($data['description']);
                $updated = true;
            }
        } elseif ($isPut) {
            if (null !== $task->getDescription()) {
                $task->setDescription(null); // Allow null description for PUT
                $updated = true;
            }
        }

        if (array_key_exists('dueAt', $data)) {
            try {
                $newDate = $data['dueAt'] ? new \DateTimeImmutable($data['dueAt']) : null;
                if ($task->getDueAt() != $newDate) { // DateTimeImmutable comparison needs care, != might work here
                    $task->setDueAt($newDate);
                    $updated = true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Invalid date format for dueAt during task update', ['id' => $id, 'dueAt' => $data['dueAt'], 'exception' => $e->getMessage()]);
                throw new \InvalidArgumentException("Invalid date format for dueAt: {$data['dueAt']}");
            }
        } elseif ($isPut) {
            // Decide if dueAt is mandatory for PUT or can be null
            if (null !== $task->getDueAt()) {
                $task->setDueAt(null);
                $updated = true;
            }
            // OR: throw new \InvalidArgumentException("Missing required field: dueAt for PUT request.");
        }

        if (array_key_exists('priority', $data)) {
            try {
                $newPriority = TaskPriority::from($data['priority']);
                if ($task->getPriority() !== $newPriority) {
                    $task->setPriority($newPriority);
                    $updated = true;
                }
            } catch (\ValueError $e) {
                $this->logger->warning('Invalid priority value during task update', ['id' => $id, 'priority' => $data['priority']]);
                throw new \InvalidArgumentException("Invalid priority value: {$data['priority']}");
            }
        } elseif ($isPut) {
            if (TaskPriority::MEDIUM !== $task->getPriority()) {
                $task->setPriority(TaskPriority::MEDIUM); // Default for PUT if not provided
                $updated = true;
            }
        }

        if (array_key_exists('status', $data)) {
            try {
                $newStatus = TaskStatus::from($data['status']);
                if ($task->getStatus() !== $newStatus) {
                    $task->setStatus($newStatus);
                    $updated = true;
                }
            } catch (\ValueError $e) {
                $this->logger->warning('Invalid status value during task update', ['id' => $id, 'status' => $data['status']]);
                throw new \InvalidArgumentException("Invalid status value: {$data['status']}");
            }
        } elseif ($isPut) {
            if (TaskStatus::PENDING !== $task->getStatus()) {
                $task->setStatus(TaskStatus::PENDING); // Default for PUT if not provided
                $updated = true;
            }
        }

        if (array_key_exists('position', $data)) {
            if (!is_numeric($data['position'])) {
                $this->logger->warning('Invalid position value during task update', ['id' => $id, 'position' => $data['position']]);
                throw new \InvalidArgumentException('Invalid position value: must be a number.');
            }
            $newPosition = (int) $data['position'];
            if ($task->getPosition() !== $newPosition) {
                $task->setPosition($newPosition);
                $updated = true;
            }
        } elseif ($isPut) {
            // Decide if position is mandatory for PUT or has a default
            $this->logger->warning('Missing required field: position for PUT request', ['id' => $id]);
            throw new \InvalidArgumentException('Missing required field: position for PUT request.'); // Or set a default
        }

        // TODO: Handle tags update (add/remove)

        if ($updated) {
            $this->entityManager->flush(); // Doctrine automatically tracks changes
            $this->logger->info('Task updated successfully', ['id' => $id]);
        } else {
            $this->logger->info('Task update requested, but no changes detected', ['id' => $id]);
        }

        return $task;
    }

    public function deleteTask(int $id): bool
    {
        $this->logger->info('Attempting to delete task', ['id' => $id]);
        $task = $this->taskRepository->find($id);
        if (!$task) {
            $this->logger->warning('Task not found for deletion', ['id' => $id]);

            return false; // Task not found
        }

        $ownerId = $task->getOwner() ? $task->getOwner()->getId() : null; // Get owner ID before removal
        $this->entityManager->remove($task);
        $this->entityManager->flush();

        $this->logger->info('Task deleted successfully', ['id' => $id, 'owner_id' => $ownerId]);

        return true;
    }
}
