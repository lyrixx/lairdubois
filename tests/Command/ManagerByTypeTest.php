<?php

namespace Tests\Command;

use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Finder\Finder;
use Tests\WebTestCase;

/**
 * The manager that ladb:change:owner gets for each type of TypableUtils: the one of the namespace of
 * its entity (Manager\Knowledge\School\TestimonialManager for Entity\Knowledge\School\Testimonial).
 */
final class ManagerByTypeTest extends WebTestCase
{
    /**
     * @return array<class-string, array{int, class-string}>
     */
    public static function provideTypes(): array
    {
        $types = [];
        foreach ((new Finder())->files()->in(__DIR__.'/../../src/Ladb/CoreBundle/Entity')->name('*.php') as $file) {
            $class = 'Ladb\\CoreBundle\\Entity\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (!\defined($class.'::TYPE') || '' === TypableUtils::getStrippedNameByType($class::TYPE)) {
                continue;
            }
            // Two entities may share a type (Vote and Thread): the one of TypableUtils
            $entityClass = ltrim(TypableUtils::getClassByType($class::TYPE), '\\');
            $types[$entityClass] = [$class::TYPE, $entityClass];
        }
        ksort($types);

        return $types;
    }

    /**
     * @dataProvider provideTypes
     */
    public function testGetManagerByType(int $type, string $entityClass): void
    {
        static::bootKernel();
        $typableUtils = static::getContainer()->get(TypableUtils::NAME);

        $managerClass = str_replace('\\Entity\\', '\\Manager\\', $entityClass).'Manager';
        if (!class_exists($managerClass)) {
            $this->expectException(ServiceNotFoundException::class);
        }

        self::assertInstanceOf($managerClass, $typableUtils->getManagerByType($type));
    }
}
