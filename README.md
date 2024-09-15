# RPEncrypter
A PocketMine-MP plugin that automatically encrypts all loaded resource packs.

[![](https://poggit.pmmp.io/shield.state/RPEncrypter)](https://poggit.pmmp.io/p/RPEncrypter)
[![](https://poggit.pmmp.io/shield.api/RPEncrypter)](https://poggit.pmmp.io/p/RPEncrypter)
[![](https://poggit.pmmp.io/shield.dl.total/RPEncrypter)](https://poggit.pmmp.io/p/RPEncrypter)

Just install the plugin and restart your server. All loaded resource packs (such as ones defined in your `resource_packs/resource_packs.yml`) will automatically be encrypted. This plugin uses your machine ID as the encryption key.

## API
[`ResourcePackEncrypter::encrypt()`](https://github.com/Muqsit/RPEncrypter/blob/master/src/muqsit/rpencrypter/ResourcePackEncrypter.php) encrypts resource packs with the supplied encryption-key.

```php
/** @var PluginBase $plugin */

// encrypt MyResourcePack.zip
$rp_path = $plugin->getDataFolder() . DIRECTORY_SEPARATOR . "MyResourcePack.zip";
$encryption_key = openssl_random_pseudo_bytes(32, $strong_result);
$file_encryption_keygen = fn(string $path, string $contents) => md5($path . $contents);

$encrypter = new ResourcePackEncrypter($plugin->getDataFolder());
$info = $encrypter->encryptZip(new ZipArchive($rp_path), $encryption_key, $file_encryption_keygen);

// register encrypted resource pack
$manager = $plugin->getServer()->getResourcePackManager();
$stack = $manager->getResourceStack();
$stack[] = $info->pack;
$manager->setResourceStack($stack);
$manager->setPackEncryptionKey($info->pack->getPackId(), $encryption_key);
```

To generate static encryption keys (instead of random keys), consider using your machine ID.
```php
use pocketmine\utils\Utils;

$machine_id = Utils::getMachineUniqueId()->getBytes();
$encryption_key = md5($machine_id); // 32-byte encryption key
```

Alternatively, generate a 32-length random string via [1Password](https://1password.com/password-generator/) and store it on your server somewhere.
```php
$encryption_key = "6]xFaeMs9b)UnybZ?raH]*)PJ.Jx!3:0";
```
