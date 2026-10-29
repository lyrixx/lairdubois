<?php

namespace Ladb\CoreBundle;

use Ladb\CoreBundle\DependencyInjection\Compiler\RemoveSessionSchemaListenerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class LadbCoreBundle extends Bundle {

	public function build(ContainerBuilder $container): void {
		parent::build($container);

		// Before the passes of DoctrineBundle, which register its listeners
		$container->addCompilerPass(new RemoveSessionSchemaListenerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
	}

}
