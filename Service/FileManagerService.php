<?php

namespace Dahovitech\FileManagerBundle\Service;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Dahovitech\FileManagerBundle\Event\FileUploadEvent;
use Dahovitech\FileManagerBundle\Event\FileDeleteEvent;
use Dahovitech\FileManagerBundle\Exception\FileManagerException;
use Dahovitech\FileManagerBundle\Exception\InvalidFileTypeException;
use Dahovitech\FileManagerBundle\Exception\FileTooLargeException;
use Dahovitech\FileManagerBundle\Exception\StorageNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FileManagerService
{
    private const DEFAULT_MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB
    private const DEFAULT_ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private array $storages,
        private EventDispatcherInterface $eventDispatcher,
        private ValidatorInterface $validator,
        private ThumbnailService $thumbnailService,
        private MetadataExtractorService $metadataExtractor,
        private LoggerInterface $logger,
        private ?CacheItemPoolInterface $cache = null,
        private array $config = []
    ) {
    }

    public function uploadFile(UploadedFile $file, ?Folder $folder = null, string $storageKey = 'local.storage'): File
    {
        // Validation des types de fichiers
        $this->validateFileType($file);
        
        // Validation de la taille
        $this->validateFileSize($file);
        
        // Validation du stockage
        $filesystem = $this->getFilesystem($storageKey);
        
        // Validation du nom de fichier
        $this->validateFilename($file->getClientOriginalName());

        // Génération d'un nom de fichier sécurisé
        $filename = $this->generateSecureFilename($file);
        $path = $this->generateFilePath($filename, $folder);

        // Création de l'entité File
        $fileEntity = new File();
        $fileEntity->setFilename($filename)
            ->setPath($path)
            ->setMimeType($file->getMimeType())
            ->setSize($file->getSize())
            ->setFolder($folder)
            ->setStorage($storageKey);

        // Calcul du hash du fichier
        $hash = hash_file('sha256', $file->getPathname());
        $fileEntity->setHash($hash);

        // Vérification des doublons
        if ($this->isDuplicate($hash)) {
            throw new FileManagerException('Un fichier identique existe déjà');
        }

        // Validation de l'entité
        $errors = $this->validator->validate($fileEntity);
        if (count($errors) > 0) {
            throw new FileManagerException('Erreurs de validation: ' . (string) $errors);
        }

        // Événement pré-upload
        $event = new FileUploadEvent($fileEntity, $file, $storageKey);
        $this->eventDispatcher->dispatch($event, FileUploadEvent::PRE_UPLOAD);

        try {
            // Upload du fichier
            $stream = fopen($file->getPathname(), 'r');
            $filesystem->writeStream($path, $stream);
            fclose($stream);

            // Extraction des métadonnées
            $metadata = $this->metadataExtractor->extractMetadata($fileEntity, $filesystem);
            $fileEntity->setMetadata($metadata);

            // Génération des thumbnails pour les images
            if ($fileEntity->isImage()) {
                $this->thumbnailService->generateThumbnails($fileEntity, $filesystem);
            }

            // Sauvegarde en base
            $this->entityManager->persist($fileEntity);
            $this->entityManager->flush();

            // Événement post-upload
            $this->eventDispatcher->dispatch($event, FileUploadEvent::POST_UPLOAD);

            // Cache invalidation
            $this->invalidateCache();

            $this->logger->info('Fichier uploadé avec succès', [
                'file_id' => $fileEntity->getId(),
                'filename' => $filename,
                'size' => $fileEntity->getSize(),
                'storage' => $storageKey
            ]);

            return $fileEntity;

        } catch (\Exception $e) {
            // Nettoyage en cas d'erreur
            try {
                if ($filesystem->fileExists($path)) {
                    $filesystem->delete($path);
                }
            } catch (\Exception $cleanupException) {
                $this->logger->warning('Erreur lors du nettoyage après échec d\'upload', [
                    'path' => $path,
                    'error' => $cleanupException->getMessage()
                ]);
            }

            $this->logger->error('Erreur lors de l\'upload du fichier', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new FileManagerException('Erreur lors de l\'upload: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deleteFile(File $file, bool $softDelete = true): void
    {
        // Événement pré-suppression
        $event = new FileDeleteEvent($file);
        $this->eventDispatcher->dispatch($event, FileDeleteEvent::PRE_DELETE);

        try {
            if ($softDelete) {
                // Suppression logique
                $file->markAsDeleted();
                $this->entityManager->flush();
            } else {
                // Suppression physique
                $filesystem = $this->getFilesystem($file->getStorage());
                
                // Suppression du fichier principal
                if ($filesystem->fileExists($file->getPath())) {
                    $filesystem->delete($file->getPath());
                }

                // Suppression des thumbnails
                if ($file->isImage()) {
                    $this->thumbnailService->deleteThumbnails($file, $filesystem);
                }

                // Suppression de l'entité
                $this->entityManager->remove($file);
                $this->entityManager->flush();
            }

            // Événement post-suppression
            $this->eventDispatcher->dispatch($event, FileDeleteEvent::POST_DELETE);

            // Cache invalidation
            $this->invalidateCache();

            $this->logger->info('Fichier supprimé', [
                'file_id' => $file->getId(),
                'filename' => $file->getFilename(),
                'soft_delete' => $softDelete
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression du fichier', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage()
            ]);

            throw new FileManagerException('Erreur lors de la suppression: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restoreFile(File $file): void
    {
        if (!$file->isDeleted()) {
            throw new FileManagerException('Le fichier n\'est pas supprimé');
        }

        $file->restore();
        $this->entityManager->flush();

        $this->invalidateCache();

        $this->logger->info('Fichier restauré', [
            'file_id' => $file->getId(),
            'filename' => $file->getFilename()
        ]);
    }

    public function createFolder(string $name, ?Folder $parent = null): Folder
    {
        // Validation du nom
        $this->validateFolderName($name);

        // Vérification des doublons
        if ($this->folderExists($name, $parent)) {
            throw new FileManagerException('Un dossier avec ce nom existe déjà');
        }

        // Vérification de la profondeur maximale
        $this->validateFolderDepth($parent);

        $folder = new Folder();
        $folder->setName($name)
               ->setParent($parent);

        // Validation de l'entité
        $errors = $this->validator->validate($folder);
        if (count($errors) > 0) {
            throw new FileManagerException('Erreurs de validation: ' . (string) $errors);
        }

        try {
            $this->entityManager->persist($folder);
            $this->entityManager->flush();

            $this->invalidateCache();

            $this->logger->info('Dossier créé', [
                'folder_id' => $folder->getId(),
                'name' => $name,
                'parent_id' => $parent?->getId()
            ]);

            return $folder;

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la création du dossier', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);

            throw new FileManagerException('Erreur lors de la création du dossier: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deleteFolder(Folder $folder, bool $recursive = false): void
    {
        if (!$recursive && (!$folder->getFiles()->isEmpty() || !$folder->getChildren()->isEmpty())) {
            throw new FileManagerException('Le dossier n\'est pas vide. Utilisez l\'option récursive pour forcer la suppression.');
        }

        try {
            if ($recursive) {
                // Suppression récursive des sous-dossiers
                foreach ($folder->getChildren() as $child) {
                    $this->deleteFolder($child, true);
                }

                // Suppression des fichiers
                foreach ($folder->getFiles() as $file) {
                    $this->deleteFile($file, false);
                }
            }

            $folder->markAsDeleted();
            $this->entityManager->flush();

            $this->invalidateCache();

            $this->logger->info('Dossier supprimé', [
                'folder_id' => $folder->getId(),
                'name' => $folder->getName(),
                'recursive' => $recursive
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression du dossier', [
                'folder_id' => $folder->getId(),
                'error' => $e->getMessage()
            ]);

            throw new FileManagerException('Erreur lors de la suppression du dossier: ' . $e->getMessage(), 0, $e);
        }
    }

    // Méthodes de validation privées

    private function validateFileType(UploadedFile $file): void
    {
        $allowedMimeTypes = $this->config['allowed_mime_types'] ?? self::DEFAULT_ALLOWED_MIME_TYPES;
        
        if (!in_array($file->getMimeType(), $allowedMimeTypes, true)) {
            throw new InvalidFileTypeException($file->getMimeType(), $allowedMimeTypes);
        }
    }

    private function validateFileSize(UploadedFile $file): void
    {
        $maxSize = $this->config['max_file_size'] ?? self::DEFAULT_MAX_FILE_SIZE;
        
        if ($file->getSize() > $maxSize) {
            throw new FileTooLargeException($file->getSize(), $maxSize);
        }
    }

    private function validateFilename(string $filename): void
    {
        // Vérification des caractères dangereux
        $dangerousChars = ['/', '\\', '..', '<', '>', ':', '"', '|', '?', '*'];
        
        foreach ($dangerousChars as $char) {
            if (str_contains($filename, $char)) {
                throw new FileManagerException("Le nom de fichier contient des caractères interdits: $char");
            }
        }

        // Vérification de la longueur
        if (strlen($filename) > 255) {
            throw new FileManagerException('Le nom de fichier est trop long (maximum 255 caractères)');
        }
    }

    private function validateFolderName(string $name): void
    {
        if (empty(trim($name))) {
            throw new FileManagerException('Le nom du dossier ne peut pas être vide');
        }

        if (strlen($name) > 255) {
            throw new FileManagerException('Le nom du dossier est trop long (maximum 255 caractères)');
        }

        // Caractères interdits
        $forbiddenChars = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
        foreach ($forbiddenChars as $char) {
            if (str_contains($name, $char)) {
                throw new FileManagerException("Le nom du dossier contient des caractères interdits: $char");
            }
        }
    }

    private function validateFolderDepth(?Folder $parent): void
    {
        $maxDepth = $this->config['max_folder_depth'] ?? 10;
        
        if ($parent && $parent->getDepth() >= $maxDepth) {
            throw new FileManagerException("Profondeur maximale de dossiers atteinte ($maxDepth niveaux)");
        }
    }

    private function getFilesystem(string $storageKey): FilesystemOperator
    {
        if (!isset($this->storages[$storageKey])) {
            throw new StorageNotFoundException($storageKey);
        }

        return $this->storages[$storageKey];
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?: 'bin';
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        $random = bin2hex(random_bytes(8));
        
        return sprintf('%s_%s.%s', $timestamp, $random, $extension);
    }

    private function generateFilePath(string $filename, ?Folder $folder): string
    {
        $basePath = '';
        
        if ($folder) {
            $basePath = $folder->getFullPath() . '/';
        }
        
        return $basePath . $filename;
    }

    private function isDuplicate(string $hash): bool
    {
        $existingFile = $this->entityManager
            ->getRepository(File::class)
            ->findOneBy(['hash' => $hash, 'isDeleted' => false]);

        return $existingFile !== null;
    }

    private function folderExists(string $name, ?Folder $parent): bool
    {
        $existingFolder = $this->entityManager
            ->getRepository(Folder::class)
            ->findOneBy([
                'name' => $name,
                'parent' => $parent,
                'isDeleted' => false
            ]);

        return $existingFolder !== null;
    }

    private function invalidateCache(): void
    {
        if ($this->cache) {
            $this->cache->clear();
        }
    }

    // Méthodes publiques utilitaires

    public function getFilesByFolder(?Folder $folder = null): array
    {
        return $this->entityManager
            ->getRepository(File::class)
            ->findBy([
                'folder' => $folder,
                'isDeleted' => false
            ], ['uploadedAt' => 'DESC']);
    }

    public function searchFiles(string $query, array $filters = []): array
    {
        $qb = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->where('f.isDeleted = :deleted')
            ->setParameter('deleted', false);

        // Recherche par nom
        if (!empty($query)) {
            $qb->andWhere('f.filename LIKE :query OR f.description LIKE :query OR f.tags LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        // Filtres
        if (isset($filters['mimeType'])) {
            $qb->andWhere('f.mimeType = :mimeType')
               ->setParameter('mimeType', $filters['mimeType']);
        }

        if (isset($filters['storage'])) {
            $qb->andWhere('f.storage = :storage')
               ->setParameter('storage', $filters['storage']);
        }

        if (isset($filters['folder'])) {
            $qb->andWhere('f.folder = :folder')
               ->setParameter('folder', $filters['folder']);
        }

        $qb->orderBy('f.uploadedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function getStorageStats(): array
    {
        $stats = [];
        
        foreach (array_keys($this->storages) as $storageKey) {
            $qb = $this->entityManager
                ->getRepository(File::class)
                ->createQueryBuilder('f')
                ->select('COUNT(f.id) as fileCount, SUM(f.size) as totalSize')
                ->where('f.storage = :storage AND f.isDeleted = :deleted')
                ->setParameter('storage', $storageKey)
                ->setParameter('deleted', false);

            $result = $qb->getQuery()->getSingleResult();
            
            $stats[$storageKey] = [
                'file_count' => (int) $result['fileCount'],
                'total_size' => (int) ($result['totalSize'] ?? 0),
                'human_readable_size' => $this->formatBytes((int) ($result['totalSize'] ?? 0))
            ];
        }
        
        return $stats;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}