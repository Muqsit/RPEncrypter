<?php

declare(strict_types=1);

namespace muqsit\rpencrypter;

use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Utils;
use RuntimeException;
use ZipArchive;
use function gettype;
use function is_string;
use function md5;
use function strlen;

final class Loader extends PluginBase{

	private ResourcePackEncrypter $encrypter;

	/** @var non-empty-string */
	private string $encryption_key;

	protected function onLoad() : void{
		$this->encrypter = new ResourcePackEncrypter($this->getDataFolder(), $this->getLogger());
	}

	protected function onEnable() : void{
		$encryption_key = $this->getConfig()->get("encryption-key", $this->createEncryptionKey());
		is_string($encryption_key) || throw new RuntimeException("Encryption key must be a string (received " . gettype($encryption_key) . ")");
		strlen($encryption_key) === 32 || throw new RuntimeException("Encryption key must be 32 digits in length (received " . strlen($encryption_key) . ")");
		$this->encryption_key = $encryption_key;

		if($this->getConfig()->get("auto-encrypt-packs", true)){
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->encryptLoadedPacks(...)), 1);
		}
	}

	/**
	 * @return non-empty-string
	 */
	public function createEncryptionKey() : string{
		$machine_id = Utils::getMachineUniqueId()->getBytes();
		return md5($machine_id, true) . md5($machine_id, true);
	}

	/**
	 * @param non-empty-string $path
	 * @param string $contents
	 * @return non-empty-string
	 */
	public function createEncryptionKeyForFile(string $path, string $contents) : string{
		return md5($path . $contents);
	}

	private function encryptLoadedPacks() : void{
		$manager = $this->getServer()->getResourcePackManager();
		$stack = $manager->getResourceStack();

		$file_encryption_keygen = $this->createEncryptionKeyForFile(...);

		foreach($stack as $index => $pack){
			if($manager->getPackEncryptionKey($pack->getPackId()) !== null){
				continue; // pack is already encrypted
			}

			if(!($pack instanceof ZippedResourcePack)){
				$this->getLogger()->warning("Resource Pack {$pack->getPackName()} is not a zipped resource pack and will not be encrypted");
				continue;
			}

			$archive = new ZipArchive();
			$archive->open($pack->getPath());
			$encrypted_pack_info = $this->encrypter->encryptZip($archive, $this->encryption_key, $file_encryption_keygen); // encrypt the pack
			$stack[$index] = $encrypted_pack_info->pack; // replace with encrypted resource pack
			$manager->setPackEncryptionKey($encrypted_pack_info->pack->getPackId(), $this->encryption_key); // store private key on server
			$this->getLogger()->debug("Successfully encrypted resource pack {$encrypted_pack_info->pack->getPackName()} [{$encrypted_pack_info->pack->getPackId()}] (path: {$encrypted_pack_info->pack->getPath()})");
		}

		$manager->setResourceStack($stack);
	}
}