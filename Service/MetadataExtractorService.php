<?php

namespace Dahovitech\FileManagerBundle\Service;

use Dahovitech\FileManagerBundle\Entity\File;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

class MetadataExtractorService
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public function extractMetadata(File $file, FilesystemOperator $filesystem): array
    {
        $metadata = [
            'extracted_at' => (new \DateTimeImmutable())->format('c'),
            'file_type' => $this->getFileType($file),
        ];

        try {
            if ($file->isImage()) {
                $metadata = array_merge($metadata, $this->extractImageMetadata($file, $filesystem));
            } elseif ($file->isVideo()) {
                $metadata = array_merge($metadata, $this->extractVideoMetadata($file, $filesystem));
            } elseif ($file->isAudio()) {
                $metadata = array_merge($metadata, $this->extractAudioMetadata($file, $filesystem));
            } elseif ($file->isPdf()) {
                $metadata = array_merge($metadata, $this->extractPdfMetadata($file, $filesystem));
            }
        } catch (\Exception $e) {
            $this->logger->warning('Erreur lors de l\'extraction des métadonnées', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $metadata;
    }

    private function getFileType(File $file): string
    {
        if ($file->isImage()) return 'image';
        if ($file->isVideo()) return 'video';
        if ($file->isAudio()) return 'audio';
        if ($file->isPdf()) return 'pdf';
        if ($file->isDocument()) return 'document';
        
        return 'other';
    }

    private function extractImageMetadata(File $file, FilesystemOperator $filesystem): array
    {
        $metadata = [];
        
        try {
            // Créer un fichier temporaire pour l'analyse
            $tempFile = tempnam(sys_get_temp_dir(), 'filemanager_');
            file_put_contents($tempFile, $filesystem->read($file->getPath()));
            
            // Obtenir les dimensions de l'image
            $imageInfo = getimagesize($tempFile);
            if ($imageInfo !== false) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['channels'] = $imageInfo['channels'] ?? null;
                $metadata['bits'] = $imageInfo['bits'] ?? null;
            }
            
            // Extraire les données EXIF
            if (function_exists('exif_read_data')) {
                $exifData = @exif_read_data($tempFile);
                if ($exifData !== false) {
                    $metadata['exif'] = $this->sanitizeExifData($exifData);
                }
            }
            
            unlink($tempFile);
            
        } catch (\Exception $e) {
            $this->logger->warning('Erreur lors de l\'extraction des métadonnées d\'image', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage()
            ]);
        }
        
        return $metadata;
    }

    private function extractVideoMetadata(File $file, FilesystemOperator $filesystem): array
    {
        // Placeholder pour l'extraction de métadonnées vidéo
        // Nécessiterait FFmpeg ou une bibliothèque similaire
        return [
            'type' => 'video',
            'note' => 'Extraction des métadonnées vidéo nécessite FFmpeg'
        ];
    }

    private function extractAudioMetadata(File $file, FilesystemOperator $filesystem): array
    {
        // Placeholder pour l'extraction de métadonnées audio
        // Nécessiterait getID3 ou une bibliothèque similaire
        return [
            'type' => 'audio',
            'note' => 'Extraction des métadonnées audio nécessite getID3'
        ];
    }

    private function extractPdfMetadata(File $file, FilesystemOperator $filesystem): array
    {
        // Placeholder pour l'extraction de métadonnées PDF
        // Nécessiterait une bibliothèque PDF comme TCPDF ou similaire
        return [
            'type' => 'pdf',
            'note' => 'Extraction des métadonnées PDF nécessite une bibliothèque spécialisée'
        ];
    }

    private function sanitizeExifData(array $exifData): array
    {
        $sanitized = [];
        
        // Données de base
        $basicFields = [
            'DateTime' => 'date_taken',
            'DateTimeOriginal' => 'date_original',
            'Make' => 'camera_make',
            'Model' => 'camera_model',
            'Orientation' => 'orientation',
            'XResolution' => 'x_resolution',
            'YResolution' => 'y_resolution',
            'Software' => 'software',
        ];
        
        foreach ($basicFields as $exifKey => $metaKey) {
            if (isset($exifData[$exifKey])) {
                $sanitized[$metaKey] = $exifData[$exifKey];
            }
        }
        
        // Données GPS si disponibles
        if (isset($exifData['GPSLatitude'], $exifData['GPSLongitude'])) {
            $sanitized['gps'] = [
                'latitude' => $this->gpsToDecimal($exifData['GPSLatitude'], $exifData['GPSLatitudeRef'] ?? 'N'),
                'longitude' => $this->gpsToDecimal($exifData['GPSLongitude'], $exifData['GPSLongitudeRef'] ?? 'E'),
            ];
        }
        
        // Paramètres de prise de vue
        $cameraSettings = [
            'FocalLength' => 'focal_length',
            'FNumber' => 'f_number',
            'ExposureTime' => 'exposure_time',
            'ISOSpeedRatings' => 'iso',
            'Flash' => 'flash',
            'WhiteBalance' => 'white_balance',
        ];
        
        foreach ($cameraSettings as $exifKey => $metaKey) {
            if (isset($exifData[$exifKey])) {
                $sanitized[$metaKey] = $exifData[$exifKey];
            }
        }
        
        return $sanitized;
    }

    private function gpsToDecimal(array $coordinate, string $hemisphere): float
    {
        $degrees = count($coordinate) > 0 ? $this->gpsCoordinateToDecimal($coordinate[0]) : 0;
        $minutes = count($coordinate) > 1 ? $this->gpsCoordinateToDecimal($coordinate[1]) : 0;
        $seconds = count($coordinate) > 2 ? $this->gpsCoordinateToDecimal($coordinate[2]) : 0;
        
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if (in_array($hemisphere, ['S', 'W'])) {
            $decimal *= -1;
        }
        
        return $decimal;
    }

    private function gpsCoordinateToDecimal(string $coordinate): float
    {
        $parts = explode('/', $coordinate);
        if (count($parts) === 2) {
            return floatval($parts[0]) / floatval($parts[1]);
        }
        
        return floatval($coordinate);
    }
}
