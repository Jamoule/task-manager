<?php

namespace App\Tests\Service;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class TaskServiceTest extends TestCase
{
    private MockObject|TaskRepository $taskRepository;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|UserRepository $userRepository;
    private MockObject|LoggerInterface $logger;
    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskRepository = $this->createMock(TaskRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->taskService = new TaskService(
            $this->taskRepository,
            $this->entityManager,
            $this->userRepository,
            $this->logger
        );
    }

    // Test methods will go here

    public function testGetTasks(): void
    {
        $task1 = (new Task())->setTitle('Test Task 1');
        $task2 = (new Task())->setTitle('Test Task 2');
        $tasks = [$task1, $task2];

        $this->taskRepository->expects($this->once())
            ->method('findBy')
            ->with([], ['createdAt' => 'ASC']) // Default sort
            ->willReturn($tasks);

        $result = $this->taskService->getTasks();
        $this->assertSame($tasks, $result);
    }

    public function testGetTasksWithFiltersAndSort(): void
    {
        $task1 = (new Task())->setTitle('Filtered Task');
        $tasks = [$task1];
        $filters = ['status' => 'pending']; // Use string value as passed from controller
        $sortBy = 'title';
        $order = 'DESC';

        $expectedCriteria = ['status' => TaskStatus::PENDING];
        $expectedOrderBy = ['title' => 'DESC'];

        $this->taskRepository->expects($this->once())
            ->method('findBy')
            ->with($expectedCriteria, $expectedOrderBy)
            ->willReturn($tasks);

        $result = $this->taskService->getTasks($filters, $sortBy, $order);
        $this->assertSame($tasks, $result);
    }

    public function testGetTasksWithInvalidStatusFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status value: invalid-status');

        $this->taskRepository->expects($this->never())->method('findBy');

        $this->taskService->getTasks(['status' => 'invalid-status']);
    }

    public function testGetTaskFound(): void
    {
        $taskId = 1;
        $task = (new Task())->setTitle('Found Task');

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $result = $this->taskService->getTask($taskId);
        $this->assertSame($task, $result);
    }

    public function testGetTaskNotFound(): void
    {
        $taskId = 999;

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $result = $this->taskService->getTask($taskId);
        $this->assertNull($result);
    }

    public function testCreateTaskSuccess(): void
    {
        // Create a mock User object for the owner
        $owner = $this->createMock(User::class); 
        $taskData = [
            'title' => 'New Task',
            'description' => 'Description here',
            'dueAt' => '2024-12-31T23:59:59+00:00',
            'priority' => 'high',
            'status' => 'in_progress',
            'position' => 1,
            // ownerId is no longer in the input data
        ];

        // No longer need to mock userRepository->find
        // $this->userRepository->expects($this->never())->method('find');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Task $task) use ($taskData, $owner) {
                $this->assertEquals($taskData['title'], $task->getTitle());
                $this->assertEquals($taskData['description'], $task->getDescription());
                // Use try-catch for DateTimeImmutable comparison if needed
                $this->assertEquals(new \DateTimeImmutable($taskData['dueAt']), $task->getDueAt());
                $this->assertEquals(TaskPriority::HIGH, $task->getPriority());
                $this->assertEquals(TaskStatus::IN_PROGRESS, $task->getStatus());
                $this->assertEquals($taskData['position'], $task->getPosition());
                // Assert that the correct owner object was set
                $this->assertSame($owner, $task->getOwner()); 
                return true;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        // Call createTask with data and the owner object
        $createdTask = $this->taskService->createTask($taskData, $owner);

        $this->assertInstanceOf(Task::class, $createdTask);
        $this->assertEquals($taskData['title'], $createdTask->getTitle());
        $this->assertSame($owner, $createdTask->getOwner());
    }
    
    public function testCreateTaskWithInvalidData(): void
    {
        $owner = $this->createMock(User::class);
        $taskData = [
            'title' => 'Valid Title',
            'priority' => 'invalid-priority', // Invalid enum value
        ];
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid priority value: invalid-priority');
        
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        
        $this->taskService->createTask($taskData, $owner);
    }

    public function testUpdateTaskPatchSuccess(): void
    {
        $taskId = 1;
        $originalOwner = $this->createMock(User::class); // Original owner
        $task = (new Task())
            ->setTitle('Original Title')
            ->setOwner($originalOwner); // Assume task exists with an owner
            
        $updateData = [
            'title' => 'Updated Title',
            'status' => 'completed', // Use the correct string value for input
            // ownerId is no longer passed or used for updates
        ];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        // No longer expect userRepository->find for owner update
        // $this->userRepository->expects($this->never())->method('find');

        $this->entityManager->expects($this->once())->method('flush');

        $updatedTask = $this->taskService->updateTask($taskId, $updateData, false); // isPut = false for PATCH

        $this->assertSame($task, $updatedTask);
        $this->assertEquals('Updated Title', $updatedTask->getTitle());
        // Compare with the correct Enum case after update
        $this->assertEquals(TaskStatus::COMPLETED, $updatedTask->getStatus()); 
        // Assert that the owner remains unchanged
        $this->assertSame($originalOwner, $updatedTask->getOwner()); 
    }

    public function testUpdateTaskPutSuccess(): void
    {
        $taskId = 2;
        $originalOwner = $this->createMock(User::class);
        $task = (new Task())->setOwner($originalOwner); // Assume task exists with an owner
        
        $updateData = [
            'title' => 'Full Update Title',
            'description' => 'Full Desc',
            'dueAt' => '2025-01-01T12:00:00+00:00',
            'priority' => 'low',
            'status' => 'pending',
            'position' => 5,
             // ownerId is no longer passed or used for updates
        ];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);
            
        // No longer expect userRepository->find for owner update
        // $this->userRepository->expects($this->never())->method('find');

        $this->entityManager->expects($this->once())->method('flush');

        $updatedTask = $this->taskService->updateTask($taskId, $updateData, true); // isPut = true for PUT

        $this->assertSame($task, $updatedTask);
        $this->assertEquals($updateData['title'], $updatedTask->getTitle());
        $this->assertEquals($updateData['description'], $updatedTask->getDescription());
        $this->assertEquals(new \DateTimeImmutable($updateData['dueAt']), $updatedTask->getDueAt());
        $this->assertEquals(TaskPriority::LOW, $updatedTask->getPriority());
        $this->assertEquals(TaskStatus::PENDING, $updatedTask->getStatus());
        $this->assertEquals($updateData['position'], $updatedTask->getPosition());
         // Assert that the owner remains unchanged
        $this->assertSame($originalOwner, $updatedTask->getOwner());
    }

    public function testUpdateTaskNotFound(): void
    {
        $taskId = 999;
        $updateData = ['title' => 'Does not matter'];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->taskService->updateTask($taskId, $updateData);
        $this->assertNull($result);
    }

    public function testUpdateTaskPutMissingRequiredField(): void
    {
        $taskId = 3;
        $task = new Task();
        $originalOwner = $this->createMock(User::class);
        $task->setOwner($originalOwner);
        
        $updateData = ['description' => 'Only description']; // Missing title, required for PUT

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        // The exact message depends on which field check comes first in your updated service logic
        $this->expectExceptionMessage('Missing required field: title for PUT request.'); 

        $this->taskService->updateTask($taskId, $updateData, true);
    }

    public function testUpdateTaskWithInvalidData(): void
    {
        $taskId = 5;
        $task = new Task();
        $originalOwner = $this->createMock(User::class);
        $task->setOwner($originalOwner);

        $updateData = ['status' => 'invalid-status']; // Invalid enum value

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status value: invalid-status');

        $this->taskService->updateTask($taskId, $updateData, false); // PATCH
    }

    public function testDeleteTaskSuccess(): void
    {
        $taskId = 1;
        $task = (new Task());

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($this->identicalTo($task));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->taskService->deleteTask($taskId);
        $this->assertTrue($result);
    }

    public function testDeleteTaskNotFound(): void
    {
        $taskId = 999;

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->taskService->deleteTask($taskId);
        $this->assertFalse($result);
    }
} 