<?php


namespace LCV\DoctrineODMPaginatorBundle\DependencyInjection;


use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{

    public function getConfigTreeBuilder()
    {

        $treeBuilder =  new TreeBuilder("lcv_doctrine_odm_paginator");

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode("soft_delete_key")->defaultValue("deletedAt")->end()
            ->end()

            ->children()
                ->arrayNode("sort_options")
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('order_key')->defaultValue('order')->end()
                        ->scalarNode('order_by_key')->defaultValue('order_by')->end()
                        ->arrayNode('descendant_values')->scalarPrototype()->defaultValue(['-1', 'desc', 'DESC', 'descendent', 'DESCENDENT'])->end()
                    ->end()
                ->end()

                ->arrayNode("pagination_options")
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('limit_key')->defaultValue('limit')->end()
                        ->scalarNode('starting_after_key')->defaultValue('starting_after')->end()
                        ->scalarNode('ending_before')->defaultValue('ending_before')->end()

                    ->end()
                ->end()

            ->end()

        ;
    }
}