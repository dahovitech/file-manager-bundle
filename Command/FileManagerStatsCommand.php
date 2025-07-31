<?php

namespace Dahovitech\FileManagerBundle\Command;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Dahovitech\FileManagerBundle\Service\FileManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:stats',
    description: 'Affiche les statistiques du gestionnaire de fichiers'
)]
class FileManagerStatsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileManagerService $fileManagerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Affiche des statistiques détaillées')
            ->addOption('by-type', 't', InputOption::VALUE_NONE, 'Groupe les statistiques par type de fichier')
            ->addOption('by-storage', 's', InputOption::VALUE_NONE, 'Groupe les statistiques par stockage')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Exporte les statistiques vers un fichier CSV')
            ->setHelp(<<<'EOF'
La commande <info>%command.name%</info> affiche les statistiques du gestionnaire de fichiers :

<info>php %command.full_name%</info>

Options disponibles :
  <comment>--detailed</comment>     Affiche des statistiques détaillées
  <comment>--by-type</comment>      Groupe les statistiques par type de fichier
  <comment>--by-storage</comment>   Groupe les statistiques par stockage
  <comment>--export=stats.csv</comment> Exporte les statistiques vers un fichier CSV
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $detailed = $input->getOption('detailed');
        $byType = $input->getOption('by-type');
        $byStorage = $input->getOption('by-storage');
        $exportFile = $input->getOption('export');

        $io->title('Statistiques du gestionnaire de fichiers');

        // Statistiques générales
        $this->displayGeneralStats($io);

        if ($detailed) {
            $this->displayDetailedStats($io);
        }

        if ($byType) {
            $this->displayStatsByType($io);
        }

        if ($byStorage) {
            $this->displayStatsByStorage($io);
        }

        if ($exportFile) {
            $this->exportStats($io, $exportFile);
        }

        return Command::SUCCESS;
    }

    private function displayGeneralStats(SymfonyStyle $io): void
    {
        $io->section('Statistiques générales');

        // Compter les fichiers actifs
        $totalFiles = $this->entityManager
            ->getRepository(File::class)
            ->count(['isDeleted' => false]);

        // Compter les fichiers supprimés
        $deletedFiles = $this->entityManager
            ->getRepository(File::class)
            ->count(['isDeleted' => true]);

        // Compter les dossiers actifs
        $totalFolders = $this->entityManager
            ->getRepository(Folder::class)
            ->count(['isDeleted' => false]);

        // Compter les dossiers supprimés
        $deletedFolders = $this->entityManager
            ->getRepository(Folder::class)
            ->count(['isDeleted' => true]);

        // Calculer la taille totale
        $totalSize = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->select('SUM(f.size)')
            ->where('f.isDeleted = false')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Taille des fichiers supprimés
        $deletedSize = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->select('SUM(f.size)')
            ->where('f.isDeleted = true')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        $table = new Table($output);
        $table->setHeaders(['Métrique', 'Valeur']);
        $table->addRows([
            ['Fichiers actifs', number_format($totalFiles)],
            ['Fichiers supprimés', number_format($deletedFiles)],
            ['Dossiers actifs', number_format($totalFolders)],
            ['Dossiers supprimés', number_format($deletedFolders)],
            ['Taille totale', $this->formatBytes($totalSize)],
            ['Taille supprimée', $this->formatBytes($deletedSize)],
            ['Taille moyenne par fichier', $totalFiles > 0 ? $this->formatBytes($totalSize / $totalFiles) : '0 B'],
        ]);
        $table->render();
    }

    private function displayDetailedStats(SymfonyStyle $io): void
    {
        $io->section('Statistiques détaillées');

        // Fichiers uploadés par mois
        $monthlyUploads = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->select("DATE_FORMAT(f.uploadedAt, '%Y-%m') as month, COUNT(f.id) as count")
            ->where('f.isDeleted = false')
            ->groupBy('month')
            ->orderBy('month', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getArrayResult();

        if (!empty($monthlyUploads)) {
            $io->text('Uploads par mois (12 derniers mois):');
            $table = new Table($output);
            $table->setHeaders(['Mois', 'Fichiers uploadés']);
            foreach ($monthlyUploads as $month) {
                $table->addRow([$month['month'], number_format($month['count'])]);
            }
            $table->render();
        }

        // Top 10 des plus gros fichiers
        $largestFiles = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->where('f.isDeleted = false')
            ->orderBy('f.size', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        if (!empty($largestFiles)) {
            $io->text('\nTop 10 des plus gros fichiers:');
            $table = new Table($output);
            $table->setHeaders(['Nom', 'Taille', 'Type', 'Date d\'upload']);
            foreach ($largestFiles as $file) {
                $table->addRow([
                    $file->getFilename(),
                    $file->getHumanReadableSize(),
                    $file->getMimeType(),
                    $file->getUploadedAt()?->format('Y-m-d H:i')
                ]);
            }
            $table->render();
        }
    }

    private function displayStatsByType(SymfonyStyle $io): void
    {
        $io->section('Statistiques par type de fichier');

        $typeStats = $this->entityManager
            ->getRepository(File::class)
            ->createQueryBuilder('f')
            ->select('f.mimeType, COUNT(f.id) as count, SUM(f.size) as totalSize')
            ->where('f.isDeleted = false')
            ->groupBy('f.mimeType')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $table = new Table($output);
        $table->setHeaders(['Type MIME', 'Nombre', 'Taille totale', 'Taille moyenne']);
        
        foreach ($typeStats as $stat) {
            $avgSize = $stat['count'] > 0 ? $stat['totalSize'] / $stat['count'] : 0;
            $table->addRow([
                $stat['mimeType'] ?? 'Inconnu',
                number_format($stat['count']),
                $this->formatBytes($stat['totalSize'] ?? 0),
                $this->formatBytes($avgSize)
            ]);
        }
        
        $table->render();
    }

    private function displayStatsByStorage(SymfonyStyle $io): void
    {
        $io->section('Statistiques par stockage');

        try {
            $stats = $this->fileManagerService->getStorageStats();
            
            $table = new Table($output);
            $table->setHeaders(['Stockage', 'Nombre de fichiers', 'Taille totale']);
            
            foreach ($stats as $storage => $stat) {
                $table->addRow([
                    str_replace('.storage', '', $storage),
                    number_format($stat['file_count']),
                    $stat['human_readable_size']
                ]);
            }
            
            $table->render();
        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des statistiques de stockage: ' . $e->getMessage());
        }
    }

    private function exportStats(SymfonyStyle $io, string $filename): void
    {
        $io->section('Export des statistiques');

        try {
            $file = fopen($filename, 'w');
            
            if (!$file) {
                throw new \Exception("Impossible d'ouvrir le fichier $filename en écriture");
            }

            // En-têtes CSV
            fputcsv($file, [
                'Nom du fichier',
                'Taille (bytes)',
                'Taille (humaine)',
                'Type MIME',
                'Stockage',
                'Dossier',
                'Date d\'upload',
                'Description',
                'Tags',
                'Public',
                'Version'
            ]);

            // Récupérer tous les fichiers actifs
            $files = $this->entityManager
                ->getRepository(File::class)
                ->findBy(['isDeleted' => false]);

            foreach ($files as $file) {
                fputcsv($file, [
                    $file->getFilename(),
                    $file->getSize(),
                    $file->getHumanReadableSize(),
                    $file->getMimeType(),
                    $file->getStorage(),
                    $file->getFolder()?->getName() ?? '',
                    $file->getUploadedAt()?->format('Y-m-d H:i:s') ?? '',
                    $file->getDescription() ?? '',
                    $file->getTags() ?? '',
                    $file->isPublic() ? 'Oui' : 'Non',
                    $file->getVersion()
                ]);
            }

            fclose($file);
            
            $io->success("Statistiques exportées vers $filename");
            $io->text(sprintf('Total: %d fichiers exportés', count($files)));
            
        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'export: ' . $e->getMessage());
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
