<?php

namespace Ladb\CoreBundle\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * The array type of DBAL 3 (a PHP array, serialize()d in a CLOB), removed in DBAL 4, under the same
 * name (config.yml): the columns and their values do not change.
 */
final class ArrayType extends Type {

	public function getSQLDeclaration(array $column, AbstractPlatform $platform): string {
		return $platform->getClobTypeDeclarationSQL($column);
	}

	public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string {
		return serialize($value);
	}

	public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed {
		if (null === $value) {
			return null;
		}

		$value = \is_resource($value) ? stream_get_contents($value) : $value;

		set_error_handler(function (int $code, string $message) use ($value): bool {
			if (\E_DEPRECATED === $code || \E_USER_DEPRECATED === $code) {
				return false;
			}

			throw ValueNotConvertible::new($value, 'array', $message);
		});

		try {
			return unserialize($value);
		} finally {
			restore_error_handler();
		}
	}

}
