<?php

namespace App\Repository;

use App\Entity\Task;
use App\Enum\TaskPriority;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Finds tasks based on criteria, applying custom sorting for priority.
     *
     * @param array $criteria
     * @param array|null $orderBy Example: ['priority' => 'DESC', 'createdAt' => 'ASC']
     * @param int|null $limit
     * @param int|null $offset
     * @return Task[]
     */
    public function findTasksFilteredAndSorted(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->createQueryBuilder('t');

        // Apply criteria
        foreach ($criteria as $field => $value) {
            $qb->andWhere($qb->expr()->eq('t.' . $field, ':' . $field))
               ->setParameter($field, $value);
        }

        // Apply sorting
        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $direction = (strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC';

                if ($field === 'priority') {
                    // Custom sort order for priority enum
                    $qb->addSelect(
                        'CASE t.priority '. // Use IDENTITY if priority is an entity
                        'WHEN :priority_high THEN 3 '.
                        'WHEN :priority_medium THEN 2 '.
                        'WHEN :priority_low THEN 1 ELSE 0 END AS HIDDEN priority_order'
                    )
                    ->setParameter('priority_high', TaskPriority::HIGH)
                    ->setParameter('priority_medium', TaskPriority::MEDIUM)
                    ->setParameter('priority_low', TaskPriority::LOW)
                    ->addOrderBy('priority_order', $direction);
                } else {
                    // Standard sorting for other fields
                    $qb->addOrderBy('t.' . $field, $direction);
                }
            }
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Task[] Returns an array of Task objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Task
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
