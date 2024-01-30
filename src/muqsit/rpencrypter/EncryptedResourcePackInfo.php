<?php

declare(strict_types=1);

namespace muqsit\rpencrypter;

use pocketmine\resourcepacks\ZippedResourcePack;

final class EncryptedResourcePackInfo{

	/**
	 * @param ZippedResourcePack $pack
	 * @param string $path
	 * @param resource $resource
	 */
	public function __construct(
		readonly public ZippedResourcePack $pack, // the encrypted resource pack
		readonly public string $path, // path to the encrypted resource pack
		readonly public mixed $resource // resource file holding the encrypted resource pack
	){}
}