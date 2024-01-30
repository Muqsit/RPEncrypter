<?php

declare(strict_types=1);

namespace muqsit\rpencrypter;

use Closure;
use InvalidArgumentException;
use JsonException;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Path;
use ZipArchive;
use function file_get_contents;
use function getmypid;
use function in_array;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_encrypt;
use function str_repeat;
use function stream_get_meta_data;
use function strlen;
use function substr;
use function tmpfile;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_RAW_DATA;

final class ResourcePackEncrypter{

	public const MANIFEST_FILE = "manifest.json";
	public const SKIP_ENCRYPTION = [self::MANIFEST_FILE, "pack_icon.png"];

	public function __construct(
		readonly public string $working_directory // directory used to store temp files when building resource packs
	){}

	/**
	 * Encrypts a resource pack that is in ZipArchive format.
	 * @see self::encrypt() for further documentation.
	 *
	 * @param ZipArchive $pack
	 * @param non-empty-string $encryption_key
	 * @param Closure(non-empty-string, string) : non-empty-string $file_encryption_keygen
	 * @return EncryptedResourcePackInfo
	 */
	public function encryptZip(ZipArchive $pack, string $encryption_key, Closure $file_encryption_keygen) : EncryptedResourcePackInfo{
		$pid = getmypid();
		$pid !== false || throw new RuntimeException("Failed to retrieve process ID");
		$temp_path = Path::join($this->working_directory, "temp" . $pid);
		Filesystem::recursiveUnlink($temp_path);
		$pack->extractTo($temp_path) || throw new RuntimeException("Failed to unpack resource pack");
		$result = $this->encrypt($temp_path, $encryption_key, $file_encryption_keygen);
		Filesystem::recursiveUnlink($temp_path);
		return $result;
	}

	/**
	 * Encrypts a supplied resource pack.
	 * Note: This method returns an object holding a temporary file. If the temporary file goes out of scope, it is
	 * automatically unlinked and renders the ZippedResourcePack broken. Make sure you store a reference to
	 * {@see EncryptedResourcePackInfo::$resource} somewhere in memory to avoid this from happening.
	 *
	 * @param non-empty-string $directory a directory containing the resource pack - the method will auto-detect nested
	 * resource packs.
	 * @param non-empty-string $encryption_key 32-byte binary string used to encrypt the overall resource pack
	 * @param Closure(non-empty-string $path, string $contents) : non-empty-string $file_encryption_keygen 32-byte binary-safe
	 * string used to encrypt each file in the resource pack
	 * @return EncryptedResourcePackInfo an object holding the encrypted resource pack.
	 */
	public function encrypt(string $directory, string $encryption_key, Closure $file_encryption_keygen) : EncryptedResourcePackInfo{
		strlen($encryption_key) === 32 || throw new InvalidArgumentException("A 32-byte encryption key must be supplied");

		// attempt to retrieve manifest file - the directory where manifest.json exists will be used as a relative path
		$manifest_dir = null;
		/** @var SplFileInfo $file */
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file){
			if($file->isFile() && $file->getFilename() === self::MANIFEST_FILE){
				$manifest_dir = $file->getPath();
				break;
			}
		}
		$manifest_dir !== null || throw new InvalidArgumentException("No manifest.json found");

		// file to hold the encrypted pack (ZIP format)
		$encrypted_pack_file = tmpfile();
		$encrypted_pack_file !== false || throw new RuntimeException("Failed to create temporary file to store encrypted resource pack");
		$encrypted_pack_path = stream_get_meta_data($encrypted_pack_file)["uri"];

		$encrypted_zip = new ZipArchive();
		$encrypted_zip->open($encrypted_pack_path, ZipArchive::OVERWRITE) === true || throw new RuntimeException("Failed to create zip archive");

		$path_key_list = [];
		$pack_uuid = null;
		/** @var SplFileInfo $file */
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($manifest_dir)) as $file){
			if(!$file->isFile()){ // skip directories
				continue;
			}

			$path = $file->getPathname();
			$contents = file_get_contents($path);
			$contents !== false || throw new RuntimeException("Failed to read file contents (path: {$path})");

			$relative_path = Path::makeRelative($path, $manifest_dir);
			if($relative_path === self::MANIFEST_FILE){
				// parse UUID from manifest.json
				try{
					$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
				}catch(JsonException){
					throw new InvalidArgumentException("Failed to parse manifest.json file");
				}
				isset($data["header"]["uuid"]) || throw new InvalidArgumentException("manifest.json must define pack UUID");
				is_string($data["header"]["uuid"]) || throw new InvalidArgumentException("Improper UUID definition in manifest.json");
				strlen($data["header"]["uuid"]) === 36 || throw new InvalidArgumentException("Improper UUID definition in manifest.json");
				$pack_uuid = $data["header"]["uuid"];
			}

			// skip encryption on manifest.json and pack_icon.png (these are necessary, otherwise the game won't be able
			// to read the resource pack)
			if(in_array($relative_path, self::SKIP_ENCRYPTION, true)){
				$encrypted_zip->addFromString($relative_path, $contents); // directly write it to the encrypted pack
				continue;
			}

			$key = $file_encryption_keygen($path, $contents);
			strlen($key) === 32 || throw new InvalidArgumentException("A 32-byte key must be supplied by the keygen");
			$iv = substr($key, 0, 16); // IV must be the first 16 digits of public key
			$encrypted_contents = openssl_encrypt($contents, "aes-256-cfb8", $key, OPENSSL_RAW_DATA, $iv);
			$encrypted_zip->addFromString($relative_path, $encrypted_contents);

			// after encrypting, we will be writing [path, key] pairs to a "contents.json" file, so we need to collect
			// every file we encrypted and the key used.
			$path_key_list[] = ["path" => $relative_path, "key" => $key];
		}

		$pack_uuid !== null || throw new RuntimeException("Failed to read pack UUID");

		try{
			$path_key_list_json = json_encode(["content" => $path_key_list], JSON_THROW_ON_ERROR);
		}catch(JsonException){
			throw new RuntimeException("Failed to compile contents.json file");
		}

		// compile the "contents.json" file - this file contains definitions of each file and their encryption key
		$stream = new BinaryStream();
		$stream->put(str_repeat("\x00", 4)); // version: 4 bytes
		$stream->put("\xfc\xb9\xcf\x9b"); // type: 4 bytes
		$stream->put(str_repeat("\x00", 8)); // padding: 8 bytes
		$stream->put("\x24"); // separator: 1 byte
		$stream->put($pack_uuid); // pack UUID: 36 bytes
		$stream->put(str_repeat("\x00", 256 - strlen($stream->getBuffer()))); // padding
		$iv = substr($encryption_key, 0, 16);
		$stream->put(openssl_encrypt($path_key_list_json, "aes-256-cfb8", $encryption_key, OPENSSL_RAW_DATA, $iv));
		$encrypted_zip->addFromString("contents.json", $stream->getBuffer());

		$encrypted_zip->close();
		return new EncryptedResourcePackInfo(new ZippedResourcePack($encrypted_pack_path), $encrypted_pack_path, $encrypted_pack_file);
	}
}
