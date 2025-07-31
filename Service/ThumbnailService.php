<?php

namespace Dahovitech\FileManagerBundle\Service;

use Dahovitech\FileManagerBundle\Entity\File;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

class ThumbnailService
{
    private const THUMBNAIL_SIZES = [
        'small' => [150, 150],
        'medium' => [300, 300],
        'large' => [600, 600],
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public function generateThumbnails(File $file, FilesystemOperator $filesystem): void
    {
        if (!$file->isImage()) {
            return;
        }

        try {
            $originalContent = $filesystem->read($file->getPath());
            $image = imagecreatefromstring($originalContent);
            
            if ($image === false) {
                $this->logger->warning('Impossible de créer une image depuis le contenu du fichier', [
                    'file_id' => $file->getId(),
                    'path' => $file->getPath()
                ]);
                return;
            }

            foreach (self::THUMBNAIL_SIZES as $size => [$width, $height]) {
                $thumbnail = $this->resizeImage($image, $width, $height);
                $thumbnailPath = $this->getThumbnailPath($file, $size);
                
                ob_start();
                imagejpeg($thumbnail, null, 85);
                $thumbnailContent = ob_get_clean();
                
                $filesystem->write($thumbnailPath, $thumbnailContent);
                imagedestroy($thumbnail);
                
                if ($size === 'medium') {
                    $file->setThumbnailPath($thumbnailPath);
                }
            }
            
            imagedestroy($image);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la génération des thumbnails', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function resizeImage($image, int $maxWidth, int $maxHeight)
    {
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int)($originalWidth * $ratio);
        $newHeight = (int)($originalHeight * $ratio);
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Préserver la transparence pour PNG
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefill($resized, 0, 0, $transparent);
        
        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );
        
        return $resized;
    }

    private function getThumbnailPath(File $file, string $size): string
    {
        $pathInfo = pathinfo($file->getPath());
        return sprintf(
            '%s/thumbnails/%s/%s_%s.jpg',
            $pathInfo['dirname'],
            $size,
            $pathInfo['filename'],
            $file->getId()
        );
    }

    public function deleteThumbnails(File $file, FilesystemOperator $filesystem): void
    {
        if (!$file->isImage()) {
            return;
        }

        foreach (array_keys(self::THUMBNAIL_SIZES) as $size) {
            try {
                $thumbnailPath = $this->getThumbnailPath($file, $size);
                if ($filesystem->fileExists($thumbnailPath)) {
                    $filesystem->delete($thumbnailPath);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Erreur lors de la suppression du thumbnail', [
                    'file_id' => $file->getId(),
                    'size' => $size,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
