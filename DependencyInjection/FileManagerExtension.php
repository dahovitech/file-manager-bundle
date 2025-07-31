<?php

namespace Dahovitech\FileManagerBundle\DependencyInjection;

use Dahovitech\FileManagerBundle\Command\FileManagerCleanupCommand;
use Dahovitech\FileManagerBundle\Command\FileManagerStatsCommand;
use Dahovitech\FileManagerBundle\Command\FileManagerSyncCommand;
use Dahovitech\FileManagerBundle\Controller\FileManagerController;
use Dahovitech\FileManagerBundle\Service\FileManagerService;
use Dahovitech\FileManagerBundle\Service\MetadataExtractorService;
use Dahovitech\FileManagerBundle\Service\ThumbnailService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class FileManagerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Enregistrer tous les paramètres de configuration
        $this->registerParameters($container, $config);

        // Enregistrer les services
        $this->registerServices($container, $config);

        // Charger la configuration YAML
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        // Configuration générale
        $container->setParameter('file_manager.upload_dir', $config['upload_dir']);
        $container->setParameter('file_manager.max_file_size', $config['max_file_size']);
        $container->setParameter('file_manager.max_folder_depth', $config['max_folder_depth']);
        $container->setParameter('file_manager.allowed_mime_types', $config['allowed_mime_types']);
        $container->setParameter('file_manager.mime_type_extensions', $config['mime_type_extensions']);

        // Configuration des thumbnails
        $container->setParameter('file_manager.thumbnails', $config['thumbnails']);

        // Configuration des métadonnées
        $container->setParameter('file_manager.metadata', $config['metadata']);

        // Configuration de sécurité
        $container->setParameter('file_manager.security', $config['security']);

        // Configuration du cache
        $container->setParameter('file_manager.cache', $config['cache']);

        // Configuration de la pagination
        $container->setParameter('file_manager.pagination', $config['pagination']);

        // Configuration des événements
        $container->setParameter('file_manager.events', $config['events']);

        // Configuration de l'interface utilisateur
        $container->setParameter('file_manager.ui', $config['ui']);

        // Configuration des routes
        $container->setParameter('file_manager.routes', $config['routes']);

        // Configuration de rate limiting
        $container->setParameter('file_manager.rate_limiting', $config['rate_limiting']);

        // Configuration complète pour les services
        $container->setParameter('file_manager.config', $config);
    }

    private function registerServices(ContainerBuilder $container, array $config): void
    {
        // Service de thumbnails
        $thumbnailServiceDefinition = new Definition(ThumbnailService::class);
        $thumbnailServiceDefinition->setArguments([
            new Reference('logger'),
        ]);
        $thumbnailServiceDefinition->addTag('monolog.logger', ['channel' => 'file_manager']);
        $container->setDefinition('file_manager.thumbnail_service', $thumbnailServiceDefinition);

        // Service d'extraction de métadonnées
        $metadataServiceDefinition = new Definition(MetadataExtractorService::class);
        $metadataServiceDefinition->setArguments([
            new Reference('logger'),
        ]);
        $metadataServiceDefinition->addTag('monolog.logger', ['channel' => 'file_manager']);
        $container->setDefinition('file_manager.metadata_extractor', $metadataServiceDefinition);

        // Service principal FileManager
        $fileManagerServiceDefinition = new Definition(FileManagerService::class);
        $fileManagerServiceDefinition->setArguments([
            new Reference('doctrine.orm.entity_manager'),
            '%flysystem_storages%', // Sera injecté par la configuration Flysystem
            new Reference('event_dispatcher'),
            new Reference('validator'),
            new Reference('file_manager.thumbnail_service'),
            new Reference('file_manager.metadata_extractor'),
            new Reference('logger'),
            $config['cache']['enabled'] ? new Reference($config['cache']['pool']) : null,
            '%file_manager.config%',
        ]);
        $fileManagerServiceDefinition->addTag('monolog.logger', ['channel' => 'file_manager']);
        $container->setDefinition('file_manager.service', $fileManagerServiceDefinition);

        // Contrôleur principal
        $controllerDefinition = new Definition(FileManagerController::class);
        $controllerDefinition->setArguments([
            '%flysystem_storages%',
            new Reference('file_manager.service'),
            new Reference('doctrine.orm.entity_manager'),
            new Reference('logger'),
            new Reference('serializer'),
            new Reference('validator'),
            $container->hasDefinition('knp_paginator') ? new Reference('knp_paginator') : null,
            $config['rate_limiting']['enabled'] ? new Reference('rate_limiter.upload') : null,
        ]);
        $controllerDefinition->addTag('controller.service_arguments');
        $container->setDefinition('file_manager.controller', $controllerDefinition);

        // Commandes CLI
        $this->registerCommands($container);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        // Commande de nettoyage
        $cleanupCommandDefinition = new Definition(FileManagerCleanupCommand::class);
        $cleanupCommandDefinition->setArguments([
            new Reference('doctrine.orm.entity_manager'),
            '%flysystem_storages%',
        ]);
        $cleanupCommandDefinition->addTag('console.command');
        $container->setDefinition('file_manager.command.cleanup', $cleanupCommandDefinition);

        // Commande de statistiques
        $statsCommandDefinition = new Definition(FileManagerStatsCommand::class);
        $statsCommandDefinition->setArguments([
            new Reference('doctrine.orm.entity_manager'),
            new Reference('file_manager.service'),
        ]);
        $statsCommandDefinition->addTag('console.command');
        $container->setDefinition('file_manager.command.stats', $statsCommandDefinition);

        // Commande de synchronisation
        $syncCommandDefinition = new Definition(FileManagerSyncCommand::class);
        $syncCommandDefinition->setArguments([
            new Reference('doctrine.orm.entity_manager'),
            '%flysystem_storages%',
            new Reference('file_manager.thumbnail_service'),
            new Reference('file_manager.metadata_extractor'),
        ]);
        $syncCommandDefinition->addTag('console.command');
        $container->setDefinition('file_manager.command.sync', $syncCommandDefinition);
    }

    public function getAlias(): string
    {
        return 'file_manager';
    }
}