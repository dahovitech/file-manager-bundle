<?php

namespace Dahovitech\FileManagerBundle\Command;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:cleanup',
    description: 'Nettoie les fichiers orphelins et supprime définitivement les fichiers marqués comme supprimés'
)]
class FileManagerCleanupCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private array $storages
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les actions sans les exécuter')
            ->addOption('orphans', null, InputOption::VALUE_NONE, 'Nettoie uniquement les fichiers orphelins')
            ->addOption('deleted', null, InputOption::VALUE_NONE, 'Supprime uniquement les fichiers marqués comme supprimés')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Supprime les fichiers supprimés depuis plus de X jours', 30)
            ->setHelp(<<<'EOF'
La commande <info>%command.name%</info> nettoie les fichiers du gestionnaire de fichiers :

<info>php %command.full_name%</info>

Options disponibles :
  <comment>--dry-run</comment>      Affiche les actions sans les exécuter
  <comment>--orphans</comment>      Nettoie uniquement les fichiers orphelins
  <comment>--deleted</comment>      Supprime uniquement les fichiers marqués comme supprimés
  <comment>--older-than=30</comment> Supprime les fichiers supprimés depuis plus de 30 jours
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $orphansOnly = $input->getOption('orphans');
        $deletedOnly = $input->getOption('deleted');
        $olderThan = (int) $input->getOption('older-than');

        $io->title('Nettoyage du gestionnaire de fichiers');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune action ne sera effectuée');
        }

        $totalCleaned = 0;
        $totalSize = 0;

        // Nettoyage des fichiers orphelins
        if (!$deletedOnly) {
            $io->section('Nettoyage des fichiers orphelins');
            [$orphansCleaned, $orphansSize] = $this->cleanOrphanFiles($io, $dryRun);
            $totalCleaned += $orphansCleaned;
            $totalSize += $orphansSize;
        }

        // Suppression définitive des fichiers marqués comme supprimés
        if (!$orphansOnly) {
            $io->section('Suppression définitive des fichiers supprimés');
            [$deletedCleaned, $deletedSize] = $this->cleanDeletedFiles($io, $dryRun, $olderThan);
            $totalCleaned += $deletedCleaned;
            $totalSize += $deletedSize;
        }

        // Nettoyage des dossiers vides
        if (!$deletedOnly && !$orphansOnly) {
            $io->section('Nettoyage des dossiers vides');
            $emptyFolders = $this->cleanEmptyFolders($io, $dryRun);
            $totalCleaned += $emptyFolders;
        }

        // Résumé
        $io->success(sprintf(
            'Nettoyage terminé ! %d éléments supprimés, %s libérés.',
            $totalCleaned,
            $this->formatBytes($totalSize)
        ));

        return Command::SUCCESS;
    }

    private function cleanOrphanFiles(SymfonyStyle $io, bool $dryRun): array
    {
        $cleaned = 0;
        $totalSize = 0;

        foreach ($this->storages as $storageKey => $filesystem) {
            $io->text("Vérification du stockage: $storageKey");
            
            try {
                // Récupérer tous les fichiers de la base de données pour ce stockage
                $dbFiles = $this->entityManager
                    ->getRepository(File::class)
                    ->createQueryBuilder('f')
                    ->select('f.path, f.size')
                    ->where('f.storage = :storage')
                    ->andWhere('f.isDeleted = false')
                    ->setParameter('storage', $storageKey)
                    ->getQuery()
                    ->getArrayResult();

                $dbFilePaths = array_column($dbFiles, 'path');
                
                // Lister tous les fichiers physiques (exemple pour stockage local)
                if ($storageKey === 'local.storage') {
                    $physicalFiles = $this->listPhysicalFiles($filesystem);
                    
                    foreach ($physicalFiles as $physicalPath) {
                        if (!in_array($physicalPath, $dbFilePaths, true)) {
                            $size = 0;
                            try {
                                $size = $filesystem->fileSize($physicalPath);
                            } catch (\Exception $e) {
                                // Ignore if can't get size
                            }
                            
                            $io->text("  Fichier orphelin trouvé: $physicalPath (" . $this->formatBytes($size) . ')');
                            
                            if (!$dryRun) {
                                try {
                                    $filesystem->delete($physicalPath);
                                    $io->text("    → Supprimé");
                                } catch (\Exception $e) {
                                    $io->error("    → Erreur lors de la suppression: " . $e->getMessage());
                                    continue;
                                }
                            }
                            
                            $cleaned++;
                            $totalSize += $size;
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->error("Erreur lors de la vérification du stockage $storageKey: " . $e->getMessage());
            }
        }

        $io->text("Fichiers orphelins trouvés: $cleaned (" . $this->formatBytes($totalSize) . ')');
        
        return [$cleaned, $totalSize];
    }

    private function cleanDeletedFiles(SymfonyStyle $io, bool $dryRun, int $olderThan): array
    {
        $cutoffDate = new \DateTimeImmutable("-$olderThan days");
        
        $qb = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->where('f.isDeleted = true')
            ->andWhere('f.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoffDate);

        $deletedFiles = $qb->getQuery()->getResult();
        $cleaned = 0;
        $totalSize = 0;

        $io->text(sprintf(
            'Fichiers supprimés avant le %s: %d',
            $cutoffDate->format('Y-m-d'),
            count($deletedFiles)
        ));

        foreach ($deletedFiles as $file) {
            $io->text("  Suppression définitive: {$file->getFilename()} (" . $file->getHumanReadableSize() . ')');
            
            if (!$dryRun) {
                try {
                    // Supprimer le fichier physique
                    if (isset($this->storages[$file->getStorage()])) {
                        $filesystem = $this->storages[$file->getStorage()];
                        if ($filesystem->fileExists($file->getPath())) {
                            $filesystem->delete($file->getPath());
                        }
                        
                        // Supprimer les thumbnails si c'est une image
                        if ($file->isImage() && $file->getThumbnailPath()) {
                            try {
                                $this->deleteThumbnails($file, $filesystem);
                            } catch (\Exception $e) {
                                $io->warning("Erreur lors de la suppression des thumbnails: " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Supprimer de la base de données
                    $this->entityManager->remove($file);
                    
                    $io->text("    → Supprimé définitivement");
                } catch (\Exception $e) {
                    $io->error("    → Erreur: " . $e->getMessage());
                    continue;
                }
            }
            
            $cleaned++;
            $totalSize += $file->getSize() ?? 0;
        }

        if (!$dryRun && $cleaned > 0) {
            $this->entityManager->flush();
        }

        $io->text("Fichiers supprimés définitivement: $cleaned (" . $this->formatBytes($totalSize) . ')');
        
        return [$cleaned, $totalSize];
    }

    private function cleanEmptyFolders(SymfonyStyle $io, bool $dryRun): int
    {
        $qb = $this->entityManager
            ->getRepository(Folder::class)
            ->createQueryBuilder('f')
            ->leftJoin('f.files', 'files')
            ->leftJoin('f.children', 'children')
            ->where('f.isDeleted = false')
            ->groupBy('f.id')
            ->having('COUNT(files.id) = 0')
            ->andHaving('COUNT(children.id) = 0');

        $emptyFolders = $qb->getQuery()->getResult();
        $cleaned = 0;

        $io->text(sprintf('Dossiers vides trouvés: %d', count($emptyFolders)));

        foreach ($emptyFolders as $folder) {
            $io->text("  Dossier vide: {$folder->getName()}");
            
            if (!$dryRun) {
                $folder->markAsDeleted();
                $io->text("    → Marqué comme supprimé");
            }
            
            $cleaned++;
        }

        if (!$dryRun && $cleaned > 0) {
            $this->entityManager->flush();
        }

        return $cleaned;
    }

    private function listPhysicalFiles(FilesystemOperator $filesystem): array
    {
        $files = [];
        
        try {
            $listing = $filesystem->listContents('/', true);
            
            foreach ($listing as $item) {
                if ($item->isFile()) {
                    $files[] = $item->path();
                }
            }
        } catch (\Exception $e) {
            // Return empty array if can't list files
        }
        
        return $files;
    }

    private function deleteThumbnails(File $file, FilesystemOperator $filesystem): void
    {
        $sizes = ['small', 'medium', 'large'];
        $pathInfo = pathinfo($file->getPath());
        
        foreach ($sizes as $size) {
            $thumbnailPath = sprintf(
                '%s/thumbnails/%s/%s_%s.jpg',
                $pathInfo['dirname'],
                $size,
                $pathInfo['filename'],
                $file->getId()
            );
            
            if ($filesystem->fileExists($thumbnailPath)) {
                $filesystem->delete($thumbnailPath);
            }
        }
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
