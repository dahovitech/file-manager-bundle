<?php

namespace Dahovitech\FileManagerBundle\Repository;

use Dahovitech\FileManagerBundle\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    public function findByFilters(?string $type = null, ?string $search = null, ?int $folderId = null, ?string $storage = null): array
    {
        $qb = $this->createQueryBuilder('f');
        if ($type) {
            $qb->andWhere('f.mimeType LIKE :type')
               ->setParameter('type', $type . '%');
        }
        if ($search) {
            $qb->andWhere('f.filename LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        if ($folderId) {
            $qb->andWhere('f.folder = :folderId')
               ->setParameter('folderId', $folderId);
        }
        if ($storage) {
            $qb->andWhere('f.storage = :storage')
               ->setParameter('storage', $storage);
        }
        return $qb->getQuery()->getResult();
    }
}