<?php

namespace Dahovitech\FileManagerBundle\Controller;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Dahovitech\FileManagerBundle\Exception\FileManagerException;
use Dahovitech\FileManagerBundle\Service\FileManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/file-manager')]
#[IsGranted('ROLE_USER')]
class FileManagerController extends AbstractController
{
    public function __construct(
        private array $storages,
        private FileManagerService $fileManagerService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private ?PaginatorInterface $paginator = null,
        private ?RateLimiterFactory $uploadLimiter = null
    ) {
    }

    #[Route('/', name: 'file_manager_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $page = max(1, $request->query->getInt('page', 1));
            $limit = min(100, max(10, $request->query->getInt('limit', 20)));
            $folderId = $request->query->getInt('folder', 0) ?: null;
            $storage = $request->query->get('storage');
            $search = $request->query->get('search', '');

            // Récupération du dossier courant
            $currentFolder = null;
            if ($folderId) {
                $currentFolder = $this->em->getRepository(Folder::class)->find($folderId);
                if (!$currentFolder || $currentFolder->isDeleted()) {
                    throw new NotFoundHttpException('Dossier introuvable');
                }
            }

            // Récupération des fichiers avec pagination
            $files = $this->getFilesForFolder($currentFolder, $search, $storage, $page, $limit);
            
            // Récupération des sous-dossiers
            $folders = $this->getFoldersForParent($currentFolder);

            // Statistiques
            $stats = $this->fileManagerService->getStorageStats();

            return $this->render('@FileManagerBundle/file_manager/index.html.twig', [
                'files' => $files,
                'folders' => $folders,
                'currentFolder' => $currentFolder,
                'currentStorage' => $storage,
                'storages' => array_keys($this->storages),
                'stats' => $stats,
                'search' => $search,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => count($files),
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'affichage de l\'index', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('error', 'Une erreur est survenue lors du chargement des fichiers.');
            
            return $this->render('@FileManagerBundle/file_manager/index.html.twig', [
                'files' => [],
                'folders' => [],
                'currentFolder' => null,
                'storages' => array_keys($this->storages),
                'stats' => [],
                'search' => '',
            ]);
        }
    }

    // ========== API REST - Gestion des fichiers ==========

    #[Route('/api/files', name: 'api_files_list', methods: ['GET'])]
    public function apiFilesList(Request $request): JsonResponse
    {
        try {
            $page = max(1, $request->query->getInt('page', 1));
            $limit = min(100, max(1, $request->query->getInt('limit', 20)));
            $folderId = $request->query->getInt('folder', 0) ?: null;
            $search = $request->query->get('search', '');
            $storage = $request->query->get('storage');
            
            $filters = array_filter([
                'storage' => $storage,
                'folder' => $folderId ? $this->em->getRepository(Folder::class)->find($folderId) : null,
            ]);

            $files = $this->fileManagerService->searchFiles($search, $filters);
            
            // Pagination manuelle (en l'absence de KnpPaginator)
            $total = count($files);
            $offset = ($page - 1) * $limit;
            $paginatedFiles = array_slice($files, $offset, $limit);

            $serializedFiles = array_map(function (File $file) {
                return $this->serializeFile($file);
            }, $paginatedFiles);

            return $this->json([
                'success' => true,
                'data' => $serializedFiles,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/api/files/{id}', name: 'api_file_details', methods: ['GET'])]
    public function apiFileDetails(File $file): JsonResponse
    {
        if ($file->isDeleted()) {
            throw new NotFoundHttpException('Fichier introuvable');
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializeFile($file, true),
        ]);
    }

    #[Route('/upload', name: 'file_manager_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        // Rate limiting pour les uploads
        if ($this->uploadLimiter) {
            $limiter = $this->uploadLimiter->create($request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Trop de tentatives d\'upload. Veuillez patienter.',
                ], 429);
            }
        }

        try {
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                return $this->json([
                    'success' => false,
                    'error' => 'Aucun fichier fourni',
                ], 400);
            }

            $folderId = $request->request->getInt('folder', 0) ?: null;
            $storageKey = $request->request->get('storage', 'local.storage');
            $description = $request->request->get('description');
            $tags = $request->request->get('tags');

            $folder = null;
            if ($folderId) {
                $folder = $this->em->getRepository(Folder::class)->find($folderId);
                if (!$folder || $folder->isDeleted()) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Dossier invalide',
                    ], 400);
                }
            }

            $fileEntity = $this->fileManagerService->uploadFile($file, $folder, $storageKey);
            
            // Ajout de métadonnées optionnelles
            if ($description) {
                $fileEntity->setDescription($description);
            }
            if ($tags) {
                $fileEntity->setTags($tags);
            }
            
            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Fichier uploadé avec succès',
                'data' => $this->serializeFile($fileEntity),
            ]);

        } catch (FileManagerException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur interne du serveur',
            ], 500);
        }
    }

    #[Route('/api/files/{id}', name: 'api_file_update', methods: ['PUT', 'PATCH'])]
    public function apiUpdateFile(File $file, Request $request): JsonResponse
    {
        if ($file->isDeleted()) {
            throw new NotFoundHttpException('Fichier introuvable');
        }

        try {
            $data = json_decode($request->getContent(), true);
            
            if (isset($data['description'])) {
                $file->setDescription($data['description']);
            }
            
            if (isset($data['tags'])) {
                $file->setTags($data['tags']);
            }
            
            if (isset($data['isPublic'])) {
                $file->setIsPublic((bool) $data['isPublic']);
            }

            $errors = $this->validator->validate($file);
            if (count($errors) > 0) {
                return $this->json([
                    'success' => false,
                    'error' => 'Erreurs de validation',
                    'details' => (string) $errors,
                ], 400);
            }

            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Fichier mis à jour avec succès',
                'data' => $this->serializeFile($file),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/delete/{id}', name: 'file_manager_delete', methods: ['POST', 'DELETE'])]
    public function delete(File $file, Request $request): JsonResponse
    {
        if ($file->isDeleted()) {
            throw new NotFoundHttpException('Fichier introuvable');
        }

        try {
            $softDelete = $request->request->getBoolean('soft_delete', true);
            $this->fileManagerService->deleteFile($file, $softDelete);

            return $this->json([
                'success' => true,
                'message' => $softDelete ? 'Fichier supprimé logiquement' : 'Fichier supprimé définitivement',
            ]);

        } catch (FileManagerException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/api/files/{id}/restore', name: 'api_file_restore', methods: ['POST'])]
    public function apiRestoreFile(File $file): JsonResponse
    {
        try {
            $this->fileManagerService->restoreFile($file);

            return $this->json([
                'success' => true,
                'message' => 'Fichier restauré avec succès',
                'data' => $this->serializeFile($file),
            ]);

        } catch (FileManagerException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    // ========== API REST - Gestion des dossiers ==========

    #[Route('/api/folders', name: 'api_folders_list', methods: ['GET'])]
    public function apiFoldersList(Request $request): JsonResponse
    {
        try {
            $parentId = $request->query->getInt('parent', 0) ?: null;
            $parent = $parentId ? $this->em->getRepository(Folder::class)->find($parentId) : null;

            $folders = $this->getFoldersForParent($parent);

            $serializedFolders = array_map(function (Folder $folder) {
                return $this->serializeFolder($folder);
            }, $folders);

            return $this->json([
                'success' => true,
                'data' => $serializedFolders,
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/folder/create', name: 'file_manager_folder_create', methods: ['POST'])]
    public function createFolder(Request $request): JsonResponse
    {
        try {
            $name = $request->request->get('name');
            $parentId = $request->request->getInt('parent', 0) ?: null;
            $description = $request->request->get('description');
            $tags = $request->request->get('tags');

            if (empty($name)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Le nom du dossier est requis',
                ], 400);
            }

            $parent = null;
            if ($parentId) {
                $parent = $this->em->getRepository(Folder::class)->find($parentId);
                if (!$parent || $parent->isDeleted()) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Dossier parent invalide',
                    ], 400);
                }
            }

            $folder = $this->fileManagerService->createFolder($name, $parent);
            
            if ($description) {
                $folder->setDescription($description);
            }
            if ($tags) {
                $folder->setTags($tags);
            }
            
            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Dossier créé avec succès',
                'data' => $this->serializeFolder($folder),
            ]);

        } catch (FileManagerException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/api/folders/{id}', name: 'api_folder_delete', methods: ['DELETE'])]
    public function apiDeleteFolder(Folder $folder, Request $request): JsonResponse
    {
        if ($folder->isDeleted()) {
            throw new NotFoundHttpException('Dossier introuvable');
        }

        try {
            $recursive = $request->query->getBoolean('recursive', false);
            $this->fileManagerService->deleteFolder($folder, $recursive);

            return $this->json([
                'success' => true,
                'message' => 'Dossier supprimé avec succès',
            ]);

        } catch (FileManagerException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    // ========== Utilitaires et endpoints spéciaux ==========

    #[Route('/select/{id}', name: 'file_manager_select', methods: ['GET'])]
    public function select(File $file): JsonResponse
    {
        if ($file->isDeleted()) {
            throw new NotFoundHttpException('Fichier introuvable');
        }

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $file->getId(),
                'filename' => $file->getFilename(),
                'url' => $this->generateUrl('file_manager_serve', ['id' => $file->getId()], true),
                'thumbnail_url' => $file->hasThumbnail() ? 
                    $this->generateUrl('file_manager_thumbnail', ['id' => $file->getId()], true) : null,
            ],
        ]);
    }

    #[Route('/serve/{id}', name: 'file_manager_serve', methods: ['GET'])]
    public function serve(File $file, Request $request): Response
    {
        if ($file->isDeleted()) {
            throw new NotFoundHttpException('Fichier introuvable');
        }

        try {
            $filesystem = $this->storages[$file->getStorage()] ?? 
                throw new \Exception('Stockage invalide: ' . $file->getStorage());

            if (!$filesystem->fileExists($file->getPath())) {
                throw new NotFoundHttpException('Fichier physique introuvable');
            }

            $stream = $filesystem->readStream($file->getPath());

            $response = new StreamedResponse(function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            });

            $response->headers->set('Content-Type', $file->getMimeType());
            $response->headers->set('Content-Length', (string) $file->getSize());
            
            // Gestion du cache
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            $response->headers->set('ETag', '"' . $file->getHash() . '"');
            
            // Vérification If-None-Match pour 304
            if ($request->headers->get('If-None-Match') === '"' . $file->getHash() . '"') {
                return new Response('', 304);
            }

            // Disposition en fonction du type de fichier
            if ($file->isImage() || $file->isPdf()) {
                $response->headers->set('Content-Disposition', 'inline; filename="' . $file->getFilename() . '"');
            } else {
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->getFilename() . '"');
            }

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du service du fichier', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage()
            ]);

            throw new NotFoundHttpException('Erreur lors de la lecture du fichier');
        }
    }

    #[Route('/thumbnail/{id}', name: 'file_manager_thumbnail', methods: ['GET'])]
    public function thumbnail(File $file, Request $request): Response
    {
        if ($file->isDeleted() || !$file->hasThumbnail()) {
            throw new NotFoundHttpException('Thumbnail introuvable');
        }

        try {
            $filesystem = $this->storages[$file->getStorage()];
            $thumbnailPath = $file->getThumbnailPath();

            if (!$filesystem->fileExists($thumbnailPath)) {
                throw new NotFoundHttpException('Thumbnail physique introuvable');
            }

            $stream = $filesystem->readStream($thumbnailPath);

            $response = new StreamedResponse(function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            });

            $response->headers->set('Content-Type', 'image/jpeg');
            $response->headers->set('Cache-Control', 'public, max-age=86400'); // 24h cache
            
            return $response;

        } catch (\Exception $e) {
            throw new NotFoundHttpException('Erreur lors de la lecture du thumbnail');
        }
    }

    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function apiStats(): JsonResponse
    {
        try {
            $stats = $this->fileManagerService->getStorageStats();
            
            return $this->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========== Méthodes privées utilitaires ==========

    private function getFilesForFolder(?Folder $folder, string $search = '', ?string $storage = null, int $page = 1, int $limit = 20): array
    {
        $filters = array_filter([
            'storage' => $storage,
            'folder' => $folder,
        ]);

        return $this->fileManagerService->searchFiles($search, $filters);
    }

    private function getFoldersForParent(?Folder $parent): array
    {
        return $this->em->getRepository(Folder::class)->findBy([
            'parent' => $parent,
            'isDeleted' => false,
        ], ['name' => 'ASC']);
    }

    private function serializeFile(File $file, bool $includeMetadata = false): array
    {
        $data = [
            'id' => $file->getId(),
            'filename' => $file->getFilename(),
            'path' => $file->getPath(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'humanReadableSize' => $file->getHumanReadableSize(),
            'uploadedAt' => $file->getUploadedAt()?->format('c'),
            'updatedAt' => $file->getUpdatedAt()?->format('c'),
            'storage' => $file->getStorage(),
            'description' => $file->getDescription(),
            'tags' => $file->getTagsArray(),
            'isPublic' => $file->isPublic(),
            'version' => $file->getVersion(),
            'isImage' => $file->isImage(),
            'isVideo' => $file->isVideo(),
            'isAudio' => $file->isAudio(),
            'isPdf' => $file->isPdf(),
            'isDocument' => $file->isDocument(),
            'hasThumbnail' => $file->hasThumbnail(),
            'folder' => $file->getFolder() ? [
                'id' => $file->getFolder()->getId(),
                'name' => $file->getFolder()->getName(),
                'fullPath' => $file->getFolder()->getFullPath(),
            ] : null,
            'urls' => [
                'serve' => $this->generateUrl('file_manager_serve', ['id' => $file->getId()], true),
                'select' => $this->generateUrl('file_manager_select', ['id' => $file->getId()], true),
            ],
        ];

        if ($file->hasThumbnail()) {
            $data['urls']['thumbnail'] = $this->generateUrl('file_manager_thumbnail', ['id' => $file->getId()], true);
        }

        if ($includeMetadata && $file->getMetadata()) {
            $data['metadata'] = $file->getMetadata();
        }

        return $data;
    }

    private function serializeFolder(Folder $folder): array
    {
        return [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'fullPath' => $folder->getFullPath(),
            'depth' => $folder->getDepth(),
            'description' => $folder->getDescription(),
            'tags' => $folder->getTagsArray(),
            'isPublic' => $folder->isPublic(),
            'createdAt' => $folder->getCreatedAt()?->format('c'),
            'updatedAt' => $folder->getUpdatedAt()?->format('c'),
            'hasChildren' => $folder->hasChildren(),
            'hasFiles' => $folder->hasFiles(),
            'totalFilesCount' => $folder->getTotalFilesCount(),
            'totalSize' => $folder->getTotalSize(),
            'humanReadableTotalSize' => $folder->getHumanReadableTotalSize(),
            'parent' => $folder->getParent() ? [
                'id' => $folder->getParent()->getId(),
                'name' => $folder->getParent()->getName(),
            ] : null,
        ];
    }
}