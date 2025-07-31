<?php

namespace Dahovitech\FileManagerBundle\Command;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Service\MetadataExtractorService;
use Dahovitech\FileManagerBundle\Service\ThumbnailService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:sync',
    description: 'Synchronise les fichiers entre la base de données et le stockage physique'
)]
class FileManagerSyncCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private array $storages,
        private ThumbnailService $thumbnailService,
        private MetadataExtractorService $metadataExtractor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les actions sans les exécuter')
            ->addOption('regenerate-thumbnails', null, InputOption::VALUE_NONE, 'Régénère tous les thumbnails')
            ->addOption('update-metadata', null, InputOption::VALUE_NONE, 'Met à jour toutes les métadonnées')
            ->addOption('fix-missing', null, InputOption::VALUE_NONE, 'Marque comme supprimés les fichiers physiquement absents')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Limite la synchronisation à un stockage spécifique')
            ->setHelp(<<<'EOF'
La commande <info>%command.name%</info> synchronise les fichiers :

<info>php %command.full_name%</info>

Options disponibles :
  <comment>--dry-run</comment>                 Affiche les actions sans les exécuter
  <comment>--regenerate-thumbnails</comment>   Régénère tous les thumbnails
  <comment>--update-metadata</comment>         Met à jour toutes les métadonnées
  <comment>--fix-missing</comment>             Marque comme supprimés les fichiers physiquement absents
  <comment>--storage=local.storage</comment>   Limite la synchronisation à un stockage spécifique
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $regenerateThumbnails = $input->getOption('regenerate-thumbnails');
        $updateMetadata = $input->getOption('update-metadata');
        $fixMissing = $input->getOption('fix-missing');
        $storageFilter = $input->getOption('storage');

        $io->title('Synchronisation du gestionnaire de fichiers');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune action ne sera effectuée');
        }

        $storagesToProcess = $storageFilter 
            ? [$storageFilter => $this->storages[$storageFilter] ?? null]
            : $this->storages;

        if ($storageFilter && !isset($this->storages[$storageFilter])) {
            $io->error("Stockage '$storageFilter' introuvable");
            return Command::FAILURE;
        }

        $totalProcessed = 0;
        $totalErrors = 0;

        foreach ($storagesToProcess as $storageKey => $filesystem) {
            $io->section("Traitement du stockage: $storageKey");
            
            if (!$filesystem instanceof FilesystemOperator) {
                $io->error("Stockage '$storageKey' non configuré correctement");
                continue;
            }

            [$processed, $errors] = $this->processStorage(
                $io, 
                $storageKey, 
                $filesystem, 
                $dryRun, 
                $regenerateThumbnails, 
                $updateMetadata, 
                $fixMissing
            );
            
            $totalProcessed += $processed;
            $totalErrors += $errors;
        }

        if ($totalErrors > 0) {
            $io->warning(sprintf(
                'Synchronisation terminée avec %d erreurs. %d fichiers traités.',
                $totalErrors,
                $totalProcessed
            ));
        } else {
            $io->success(sprintf(
                'Synchronisation terminée avec succès ! %d fichiers traités.',
                $totalProcessed
            ));
        }

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processStorage(
        SymfonyStyle $io,
        string $storageKey,
        FilesystemOperator $filesystem,
        bool $dryRun,
        bool $regenerateThumbnails,
        bool $updateMetadata,
        bool $fixMissing
    ): array {
        $processed = 0;
        $errors = 0;

        // Récupérer tous les fichiers de ce stockage
        $files = $this->entityManager
            ->getRepository(File::class)
            ->findBy([
                'storage' => $storageKey,
                'isDeleted' => false
            ]);

        $io->progressStart(count($files));

        foreach ($files as $file) {
            try {
                $fileExists = $filesystem->fileExists($file->getPath());
                
                if (!$fileExists && $fixMissing) {
                    $io->newLine();
                    $io->text("  Fichier physique manquant: {$file->getFilename()}");
                    
                    if (!$dryRun) {
                        $file->markAsDeleted();
                        $io->text("    → Marqué comme supprimé");
                    }
                } elseif ($fileExists) {
                    // Régénération des thumbnails
                    if ($regenerateThumbnails && $file->isImage()) {
                        $io->newLine();
                        $io->text("  Régénération thumbnail: {$file->getFilename()}");
                        
                        if (!$dryRun) {
                            try {
                                $this->thumbnailService->generateThumbnails($file, $filesystem);
                                $io->text("    → Thumbnail régénéré");
                            } catch (\Exception $e) {
                                $io->text("    → Erreur: " . $e->getMessage());
                                $errors++;
                            }
                        }
                    }
                    
                    // Mise à jour des métadonnées
                    if ($updateMetadata) {
                        $io->newLine();
                        $io->text("  Mise à jour métadonnées: {$file->getFilename()}");
                        
                        if (!$dryRun) {
                            try {
                                $metadata = $this->metadataExtractor->extractMetadata($file, $filesystem);
                                $file->setMetadata($metadata);
                                $io->text("    → Métadonnées mises à jour");
                            } catch (\Exception $e) {
                                $io->text("    → Erreur: " . $e->getMessage());
                                $errors++;
                            }
                        }
                    }
                    
                    // Vérification de la taille
                    try {
                        $actualSize = $filesystem->fileSize($file->getPath());
                        if ($file->getSize() !== $actualSize) {
                            $io->newLine();
                            $io->text(sprintf(
                                "  Correction taille: %s (%d → %d bytes)",
                                $file->getFilename(),
                                $file->getSize(),
                                $actualSize
                            ));
                            
                            if (!$dryRun) {
                                $file->setSize($actualSize);
                            }
                        }
                    } catch (\Exception $e) {
                        $io->newLine();
                        $io->text("  Erreur vérification taille: " . $e->getMessage());
                        $errors++;
                    }
                }
                
                $processed++;
                
            } catch (\Exception $e) {
                $io->newLine();
                $io->error("Erreur lors du traitement de {$file->getFilename()}: " . $e->getMessage());
                $errors++;
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        // Sauvegarder les changements
        if (!$dryRun && $processed > 0) {
            try {
                $this->entityManager->flush();
                $io->text("\nChangements sauvegardés en base de données");
            } catch (\Exception $e) {
                $io->error("Erreur lors de la sauvegarde: " . $e->getMessage());
                $errors++;
            }
        }
        
        return [$processed, $errors];
    }
}
