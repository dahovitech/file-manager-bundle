<?php

namespace Dahovitech\FileManagerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('file_manager');
        
        $rootNode = $treeBuilder->getRootNode();
        
        $rootNode
            ->children()
                // Configuration générale
                ->scalarNode('upload_dir')
                    ->defaultValue('%kernel.project_dir%/public/uploads')
                    ->info('Répertoire d\'upload par défaut pour le stockage local')
                ->end()
                
                ->integerNode('max_file_size')
                    ->defaultValue(52428800) // 50MB
                    ->min(1)
                    ->info('Taille maximale de fichier en bytes (défaut: 50MB)')
                ->end()
                
                ->integerNode('max_folder_depth')
                    ->defaultValue(10)
                    ->min(1)
                    ->info('Profondeur maximale de dossiers imbriqués')
                ->end()
                
                // Types de fichiers autorisés
                ->arrayNode('allowed_mime_types')
                    ->info('Types MIME autorisés pour l\'upload')
                    ->prototype('scalar')->end()
                    ->defaultValue([
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
                        'video/mp4',
                        'video/avi',
                        'video/quicktime',
                        'audio/mpeg',
                        'audio/wav',
                        'audio/ogg',
                    ])
                ->end()
                
                // Configuration des extensions par type MIME
                ->arrayNode('mime_type_extensions')
                    ->info('Mapping des types MIME vers les extensions de fichier')
                    ->useAttributeAsKey('mime_type')
                    ->prototype('scalar')->end()
                    ->defaultValue([
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'application/pdf' => 'pdf',
                        'text/plain' => 'txt',
                        'text/csv' => 'csv',
                        'video/mp4' => 'mp4',
                        'audio/mpeg' => 'mp3',
                    ])
                ->end()
                
                // Configuration des thumbnails
                ->arrayNode('thumbnails')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Active la génération automatique de thumbnails')
                        ->end()
                        ->integerNode('quality')
                            ->defaultValue(85)
                            ->min(1)
                            ->max(100)
                            ->info('Qualité JPEG des thumbnails (1-100)')
                        ->end()
                        ->arrayNode('sizes')
                            ->info('Tailles de thumbnails à générer')
                            ->useAttributeAsKey('name')
                            ->prototype('array')
                                ->children()
                                    ->integerNode('width')->min(1)->end()
                                    ->integerNode('height')->min(1)->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                'small' => ['width' => 150, 'height' => 150],
                                'medium' => ['width' => 300, 'height' => 300],
                                'large' => ['width' => 600, 'height' => 600],
                            ])
                        ->end()
                    ->end()
                ->end()
                
                // Configuration des métadonnées
                ->arrayNode('metadata')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('extract_exif')
                            ->defaultTrue()
                            ->info('Extrait les données EXIF des images')
                        ->end()
                        ->booleanNode('extract_gps')
                            ->defaultTrue()
                            ->info('Extrait les données GPS des images')
                        ->end()
                        ->booleanNode('extract_video_info')
                            ->defaultFalse()
                            ->info('Extrait les métadonnées vidéo (nécessite FFmpeg)')
                        ->end()
                        ->booleanNode('extract_audio_info')
                            ->defaultFalse()
                            ->info('Extrait les métadonnées audio (nécessite getID3)')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration de sécurité
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('scan_viruses')
                            ->defaultFalse()
                            ->info('Active le scan antivirus (nécessite ClamAV)')
                        ->end()
                        ->booleanNode('check_file_signature')
                            ->defaultTrue()
                            ->info('Vérifie la signature du fichier pour détecter les faux types MIME')
                        ->end()
                        ->booleanNode('sanitize_filename')
                            ->defaultTrue()
                            ->info('Assainit automatiquement les noms de fichiers')
                        ->end()
                        ->arrayNode('forbidden_extensions')
                            ->prototype('scalar')->end()
                            ->defaultValue(['exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar', 'ws', 'wsf'])
                            ->info('Extensions de fichier interdites')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration du cache
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Active le cache des métadonnées')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(3600)
                            ->min(1)
                            ->info('Durée de vie du cache en secondes')
                        ->end()
                        ->scalarNode('pool')
                            ->defaultValue('cache.app')
                            ->info('Service de pool de cache à utiliser')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration de la pagination
                ->arrayNode('pagination')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('default_page_size')
                            ->defaultValue(20)
                            ->min(1)
                            ->max(100)
                            ->info('Nombre d\'éléments par page par défaut')
                        ->end()
                        ->integerNode('max_page_size')
                            ->defaultValue(100)
                            ->min(1)
                            ->info('Nombre maximum d\'éléments par page')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration des événements
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('dispatch_upload_events')
                            ->defaultTrue()
                            ->info('Déclenche les événements lors des uploads')
                        ->end()
                        ->booleanNode('dispatch_delete_events')
                            ->defaultTrue()
                            ->info('Déclenche les événements lors des suppressions')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration de l'interface utilisateur
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('theme')
                            ->defaultValue('default')
                            ->info('Thème de l\'interface utilisateur')
                        ->end()
                        ->booleanNode('show_breadcrumb')
                            ->defaultTrue()
                            ->info('Affiche le fil d\'Ariane')
                        ->end()
                        ->booleanNode('show_stats')
                            ->defaultTrue()
                            ->info('Affiche les statistiques de stockage')
                        ->end()
                        ->booleanNode('enable_drag_drop')
                            ->defaultTrue()
                            ->info('Active le glisser-déposer')
                        ->end()
                        ->scalarNode('default_view')
                            ->info('Vue par défaut (grid ou list)')
                            ->validate()
                                ->ifNotInArray(['grid', 'list'])
                                ->thenInvalid('La vue doit être "grid" ou "list"')
                            ->end()
                            ->defaultValue('grid')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration des routes
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('prefix')
                            ->defaultValue('/file-manager')
                            ->info('Préfixe des routes du gestionnaire de fichiers')
                        ->end()
                        ->booleanNode('api_enabled')
                            ->defaultTrue()
                            ->info('Active les routes API REST')
                        ->end()
                    ->end()
                ->end()
                
                // Configuration de rate limiting
                ->arrayNode('rate_limiting')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Active la limitation de taux pour les uploads')
                        ->end()
                        ->integerNode('max_uploads_per_minute')
                            ->defaultValue(10)
                            ->min(1)
                            ->info('Nombre maximum d\'uploads par minute par IP')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}