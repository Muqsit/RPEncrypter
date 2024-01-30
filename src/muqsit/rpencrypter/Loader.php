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

	/** @var non-empty-string */
	private string $encryption_key;

	/** @var list<resource> */
	private array $encrypted_pack_resources = [];

	protected function onEnable() : void{
		$encryption_key = $this->getConfig()->get("encryption-key", $this->createEncryptionKey());
		is_string($encryption_key) || throw new RuntimeException("Encryption key must be a string (received " . gettype($encryption_key) . ")");
		strlen($encryption_key) === 32 || throw new RuntimeException("Encryption key must be 32 digits in length (received " . strlen($encryption_key) . ")");
		$this->encryption_key = $encryption_key;

		if($this->getConfig()->get("auto-encrypt-packs", true)){
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->encryptLoadedPacks(...)), 1);
		}
	}

	protected function onDisable() : void{
		// References to tmpfile() resources must be held during runtime otherwise the
		// ZippedResourcePack instances holding them will fail to read them during runtime.
		// As these are tmpfile() resources, the file will be automatically deleted when they
		// go out of reference (e.g., during plugin disable like we are doing below).
		unset($this->encrypted_pack_resources);
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
		$encrypter = new ResourcePackEncrypter($this->getDataFolder());
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
			$encrypted_pack_info = $encrypter->encryptZip($archive, $this->encryption_key, $file_encryption_keygen); // encrypt the pack
			$stack[$index] = $encrypted_pack_info->pack; // replace with encrypted resource pack
			$this->encrypted_pack_resources[] = $encrypted_pack_info->resource; // hold reference to encrypted pack file resource
			$manager->setPackEncryptionKey($pack->getPackId(), $this->encryption_key); // store private key on server
			$this->getLogger()->debug("Successfully encrypted resource pack {$pack->getPackName()} [{$pack->getPackId()}]");
		}

		$manager->setResourceStack($stack);
	}
}