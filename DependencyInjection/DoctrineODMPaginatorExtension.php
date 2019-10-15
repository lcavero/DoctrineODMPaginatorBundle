<?php


namespace LCV\DoctrineODMPaginatorBundle\DependencyInjection;


use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class DoctrineODMPaginatorExtension extends Extension
{

    public function getAlias()
    {
        return 'lcv_doctrine_odm_paginator';
    }

    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $definition = $container->getDefinition('LCV\DoctrineODMPaginatorBundle\Pagination\Paginator');
        $definition->replaceArgument(2, $config['sort_options']);
        $definition->replaceArgument(3, $config['pagination_options']);
        $definition->replaceArgument(4, $config['soft_delete_key']);
    }
}