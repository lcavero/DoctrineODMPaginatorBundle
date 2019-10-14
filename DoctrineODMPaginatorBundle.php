<?php

namespace LCV\DoctrineODMPaginatorBundle;

use LCV\DoctrineODMPaginatorBundle\DependencyInjection\DoctrineODMPaginatorExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DoctrineODMPaginatorBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new DoctrineODMPaginatorExtension();
    }

}
