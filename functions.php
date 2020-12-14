<?php



function curlget(string $url, array $headers = null, array $post = null, array $opt_arr = [])
{
  //param1 -> url for cURL, param2 -> pass array to be used as header
	$handle = curl_init();
	curl_setopt($handle, CURLOPT_URL, $url);
	if(isset($headers))  curl_setopt($handle, CURLOPT_HTTPHEADER, $headers) ;
	if(!empty($post)) curl_setopt($handle, CURLOPT_POSTFIELDS, $post);
	if(!empty($opt_arr)) curl_setopt_array($handle, $opt_arr);

	curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
	curl_error($handle);
	$response = curl_exec($handle);
	curl_close($handle);

	$json = json_decode(trim($response));
  return (is_object($json) ? $json : $response);
}

function print_usage()
{
  global $argv, $options;
  echo("Telnetd Ramdisk\n");
  echo("Lets you boot your device with a ramdisk and connect to it using telnet\n\n");
  echo("OPTIONS:\n");
  foreach($options as $opt_short => $option)
  {
    echo("  -$opt_short\t{$option['help']}\n");
  }
  echo("\nExample: php {$argv[0]} -d iPhone10,6 -b D221AP -v 13.0 -s 13.0.shsh2\n");
  echo("shsh2 file can be from any version but needs to be from your device (ecid). Use: https://shsh.host/\n");
  echo("if device parameters aren't specified, the program will attempt to detect DFU devices attached to this machine\n\n");
}

function ask($question){
  echo("\e[1m\x1B[31m{$question}\e[0m\e[0m\n");
  $h = fopen("php://stdin","r");
  $answer = fgets($h);
  fclose($h);
  return ($answer === "y" . PHP_EOL || $answer === "yes" . PHP_EOL);
}

function mount_rd(){
  verbinfo("Mounting ramdisk.dmg");
  $try_mount = execute("hdiutil attach ramdisk.dmg 2>&1",1);
  $mount_point = explode(" ",preg_replace('/\s+/', ' ', $try_mount));
  unset($mount_point[0]);
  unset($mount_point[sizeof($mount_point)]);
  $mount_point = implode(" ",$mount_point);
  return $mount_point;
}

function unmount($mountpoint){
  return execute("hdiutil detach \"{$mountpoint}\" 2>&1",1);
}

function detect_device(): ?object
{
	$device_properties = array();
	$command = "irecovery -q";
	$stdout = execute($command, true);
	if(preg_match_all("/(.+?)\:.\s*(?:(0x[0-9a-f]+)|(.+))\n/i", $stdout, $matches))
	{
		foreach($matches[1] as $index => $match)
		{
			if(!empty($matches[2][$index]))
			{
				$number = hexdec(trim($matches[2][$index]));
				$device_properties[trim($match)] = $number;
			}
			else
			{
				$device_properties[trim($match)] = trim($matches[3][$index]);
			}
		}
    if(empty($device_properties['CPID']) || empty($device_properties['BDID'])) return null;
    $device = find_device($device_properties['CPID'], $device_properties['BDID']);
    if($device)
    {
      $device->ecid_raw = $device_properties['ECID'];
      $device->ecid = strtoupper(dechex($device_properties['ECID']));
      return $device;
    }
	}
  return null;
}

function read_device(): bool
{
  verbinfo("Automatically detecting DFU device");
  if( !($device = detect_device()) )
  {
    return false;
  }
  $device_text = "{$device->name} ({$device->identifier}) with boardConfig {$device->BoardConfig}\n";
  $device_text .= "Is this the device you're making the ramdisk for?";
  if(ask($device_text))
  {
    define('DEVICE', $device->identifier);
    define('BOARDCONFIG', $device->BoardConfig);
    define('ECID', $device->ecid);
    return true;
  }
  return false;
}

function find_device(int $cpid, int $bdid): ?object
{
  $fw = get_firmware_data();
  // lookup in irecovery first
  $irecv_device = irecv_lookup_device($cpid, $bdid);
  if($irecv_device)
  {
    $identifier = $irecv_device[0];
    if(isset($fw->devices->$identifier))
    {
      $out_device = $fw->devices->$identifier;
      $out_device->BoardConfig = $irecv_device[1];
      $out_device->cpid = $irecv_device[3];
      $out_device->bdid = $irecv_device[2];
      $out_device->identifier = $irecv_device[0];
      return $out_device;
    }
  }
	foreach($fw->devices as $identifier => $device)
	{
		if( ($device->cpid === $cpid) && ($device->bdid === $bdid) )
		{
			$device->identifier = $identifier;
			return $device;
		}
	}
	return null;
}

function execute($command, $silent = false){
  verbinfo("Executing {$command}");
  $h = popen($command . " 2>&1","r");
  $o = "";
  while (!feof($h)) {
    $b = fread($h,1024);
    $o .= $b;
    if(!VERBOSE) {
      fwrite(LOG,$b);
      if(!$silent){
        echo $b;
      }
    } else {
      echo $b;
    }
  }
  fclose($h);
  return $o;
}

function get_firmware_data(bool $force_refresh = false): object
{
	$url = 'https://api.ipsw.me/v2.1/firmwares.json/condensed';
	$cache_path = __DIR__ . "/.cache/";
  $cache_file = $cache_path."firmwares.json";
	if(!is_dir($cache_path)) mkdir($cache_path);
	if(!file_exists($cache_file)) $force_refresh = true;
	if($force_refresh)
	{
		execute("wget $url -O $cache_file");
	}
	if(!file_exists($cache_file)) return null;
	$fw_data = file_get_contents($cache_file);
	$fw = json_decode($fw_data);
	unset($fw_data);
	return $fw;
}

function get_ipsw_url(string $device, string $version): ?string
{
  verbinfo("Getting ipsw url from ipsw.me API");
  $fw = get_firmware_data();
  if(!isset($fw->devices->$device)) return null;
  foreach($fw->devices->$device->firmwares as $firmware)
  {
    if($firmware->version == $version)
    {
      $url = $firmware->url;
      verbinfo("Got url for version: $version");
      return $url;
    }
  }
  verbinfo("Couldn't get IPSW url for $version for $device");
  return null;
}

function info($msg,$newline = true){
  $line = $GLOBALS['argv'][0] . ": {$msg}" . ($newline ? PHP_EOL : "");
  echo $line;
  if(!VERBOSE){fwrite(LOG,$line);}
}

function verbinfo($msg){
  if(VERBOSE){
    info($msg);
  } else {
    fwrite(LOG,$msg . PHP_EOL);
  }
}

function pls_install_msg($github_project){
  return "Dependency missing: Please download, compile (if needed) and install {$github_project} and make sure it's in PATH." . PHP_EOL;
}

function is_installed($cmd){
  verbinfo("Checking for {$cmd}");
  $dependency_const_name = strtoupper($cmd)."_PATH";
  if(shell_exec("which {$cmd}") === NULL){
    if(is_executable(__DIR__."/bin/$cmd")){
      define($dependency_const_name, __DIR__."/bin/$cmd");
      return true;
    }
    return false;
  } else {
    define($dependency_const_name, $cmd);
    return true;
  }
}

function remotezip_file_list(string $url, string $mask = null): array
{
	$files = array();
	if(!filter_var($url, FILTER_VALIDATE_URL)) return $files;
	$cache_file_path = __DIR__.'/.cache/remotezip'.sha1($url);
	$cache_data = file_exists($cache_file_path) ? file_get_contents($cache_file_path) : null;
	if(!strlen($cache_data))
	{
		// verbinfo("")
		$stdout = execute("/usr/local/bin/remotezip -l $url");
		if(strlen($stdout))
		{
			file_put_contents($cache_file_path, $stdout);
		}
	}
	else
	{
		$stdout = $cache_data;
	}
	// var_dump($stdout);
	$regex = "/(?<size>\d+?)\s+?(?<date>(?:.+?)\s+?(?:.+?))\s+?(?<path>.+)(?:$|\n)/i";
	if(preg_match_all($regex, $stdout, $matches))
	{
		foreach($matches['path'] as $index => $path)
		{
			if($mask && (stristr($path, $mask) === false)) continue;
			$file = array(
				'path' => trim($path),
				'size' => (int)$matches['size'][$index],
				'date' => trim($matches['date'][$index])
			);
			$files []= $file;
		}
	}
	return $files;
}

function remotezip_get_files(string $url):bool
{
	// create BoM and download files
	$files = remotezip_file_list($url);
	$dmg_files = array();
	$files_needed = array(
		'iBSS' => array(
			'regex' => "/.*iBSS.*\.im4p$/i",
			'found' => false,
			'file' => null
		),
		'iBEC' => array(
			'regex' => "/.*iBEC.*\.im4p$/i",
			'found' => false,
			'file' => null
		),
		'kernelcache' => array(
			'regex' => "/^kernelcache.*/i",
			'found' => false,
			'file' => null
		),
    'devicetree' => array(
      'regex' => sprintf("/.*DeviceTree\.%s\.im4p$/i", BOARDCONFIG),
      'found' => false,
      'file' => null
    )
    // 'BuildManifest' => array(
		// 	'regex' => "/^BuildManifest\.plist$/i",
		// 	'found' => false,
		// 	'file' => null
		// ),
	);


	foreach($files as $file)
	{
		$file_path = $file['path'];

		if(preg_match("/.*\.dmg.*/i", $file_path))
		{
			$dmg_files[$file['size']] = array(
				'regex' => null,
				'found' => true,
				'file' => $file
			);
			continue;
		}

		foreach($files_needed as $component => &$file_needed)
		{
			if($file_needed['found']) continue;
			if(preg_match($file_needed['regex'], $file_path))
			{
				$file_needed['file'] = $file;
				$file_needed['found'] = true;
			}
		}
	}

	$sizes = array_keys($dmg_files);
	rsort($sizes);
	unset($dmg_files[current($sizes)]);
	$files_needed = array_merge($files_needed, $dmg_files);

	// download files
	// bad bad php (or I could pick another variable.) $file_needed simply is a reference
	// modifying this reference will modify the item in $files_needed array as well
	// so free the reference
	unset($file_needed);
	foreach($files_needed as $file_needed)
	{
		$file = $file_needed['file'];
		if(!$file_needed['found'])
    {
      return false;
    }
		execute("remotezip $url {$file['path']}");
		if(!file_exists($file['path'])) return false;
	}
  execute("remotezip $url BuildManifest.plist");
	return true;
}

function add_dependency_ldid2(): bool
{
  $base_url = 'https://api.github.com';
  $user_name = 'xerub';
  $repo_name = 'ldid';
  $url = "$base_url/repos/$user_name/$repo_name/releases";
  // $url = "$base_url/repos/pwn20wndstuff/Undecimus/releases";
  $working_path = __DIR__."/dependencies/$repo_name";

  $data = curlget($url, ['User-Agent: make_telnet_rd']);
  $releases = json_decode($data);
  if(empty($releases)) return false;
  // var_dump($releases);die();

  $latest_release = null;
  $iter_cmp_timetamp = 0;
  foreach($releases as $release)
  {
    $release_published_timestamp = strtotime($release->published_at);
    if($release_published_timestamp > $iter_cmp_timetamp)
    {
      $latest_release = $release;
      $iter_cmp_timetamp = $release_published_timestamp;
    }
  }
  verbinfo("Selected {$latest_release->tag_name} of $repo_name");
  if(empty($latest_release->assets))
  {
    return false;
  }

  if(!is_dir($working_path))
  {
    execute("mkdir -p $working_path");
  }

  foreach($latest_release->assets as $asset)
  {
    $dl_url = $asset->browser_download_url;
    $dl_url_pathinfo = pathinfo($dl_url);
    $dl_file_path = "$working_path/{$dl_url_pathinfo['basename']}";
    if($dl_url_pathinfo['extension'] === 'zip')
    {
      execute("wget $dl_url -O $dl_file_path");
      if(!file_exists($dl_file_path))
      {
        continue;
      }
      $z = new ZipArchive();
      $z_open_success = $z -> open($dl_file_path);
      if($z_open_success === true)
      {
        if($z->locateName('ldid2') === false)
        {
          continue;
        }
        $z_extract_success = $z -> extractTo($working_path, array('ldid2'));
        if(!$z_extract_success)
        {
          continue;
        }
        $binary_path = "$working_path/ldid2";
        if(!is_executable($binary_path))
        {
          chmod($binary_path, 0755);
          if(!is_dir(__DIR__."/bin"))
          {
            mkdir(__DIR__."/bin");
          }
          if(rename($binary_path, __DIR__."/bin/ldid2"))
          {
            return true;
          }
        }
      }
    }
  }

  return false;
}













?>
