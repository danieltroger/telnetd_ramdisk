#!/usr/bin/env php
<?php
/**************************************

Automatically creates a RAMdisk for 64 bit iOS devices by bundling a bunch of other utilities. Modifies the RAMdisk to enable a remote shell over telnet and adds a bunch of utilities.
Copyright (C) 2020  Daniel Troger

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
**************************************/

define("VERBOSE",true);
include('devices.php');
include('functions.php');
include('CFPropertyList/src/CFPropertyList/CFPropertyList.php');

$options = array(
  'd' => array(
    'longopt' => 'device',
    'human_text' => 'device identifier',
    'help' => 'Device identifier (example: iPhone10,4)'
  ),
  'b' => array(
    'longopt' => 'boardconfig',
    'human_text' => 'BoardConfig',
    'help' => 'Boardconfig (example: d201ap)'
  ),
  'v' => array(
    'longopt' => 'version',
    'human_text' => 'iOS version',
    'help' => 'iOS version to use as base for ramdisk'
  ),
  's' => array(
    'longopt' => 'shsh2',
    'human_text' => 'shsh2 file',
    'help' => 'shsh2 file (can be any version)'
  ),
  'h' => array(
    'longopt' => 'help',
    'human_text' => null,
    'help' => 'print this help text'
  ),
);


$shortopts = implode(':', array_keys($options));
$args = getopt($shortopts);

if(isset($args['h']))
{
  print_usage();
  exit(0);
}

if(empty($args['d']) || empty($args['b']))
{
  read_device();
}

foreach($options as $opt_short => $opt)
{
  if(!$opt['human_text']) continue;
  $constant_name = strtoupper($opt['longopt']);
  if(defined($constant_name)) continue;
  if(empty($args[$opt_short]))
  {
    echo("[ERROR] {$opt['human_text']} not specified (use -$opt_short)\n\n");
    print_usage();
    exit(-1);
  }
  define(strtoupper($opt['longopt']), $args[$opt_short]);
}

define("WD","WD_" . DEVICE . "-" . BOARDCONFIG . "-" . VERSION . "_telnet_rd");

// create logging handle if not VERBOSE
if(!VERBOSE){
  define("LOG",fopen("log.txt","a"));
  fwrite(LOG,"telnet_rd_run|" . date("c") . PHP_EOL);
}

verbinfo("checking for .shsh2 file");
if(!file_exists(SHSH2)){
  die(".shsh2 file doesn't exist or isn't readable" . PHP_EOL);
}
define("SHSH2_ABS",realpath(SHSH2)); // I shouldn't have started using constants lol

verbinfo("Checking for dependencies");
if(!is_installed("diskutil") || !is_installed("hdiutil") || !is_installed("unzip") || !is_installed("plutil") || !is_installed("curl")){
  die("Couldn't find diskutil, unzip, plutil, curl or hdiutil. Please use a mac." . PHP_EOL);
}
$dependencies = array(
  "img4" => "https://github.com/xerub/img4lib",
  "img4tool" => "https://github.com/tihmstar/img4tool",
  "ldid2"=>"https://github.com/xerub/ldid/releases/",
  "autodecrypt" => "https://github.com/matteyeux/autodecrypt",
  "kairos" => "https://github.com/dayt0n/kairos",
  "iproxy" => "https://github.com/libimobiledevice/libusbmuxd",
  "Kernel64Patcher" => "https://github.com/Ralph0045/Kernel64Patcher",
  "irecovery" => "https://github.com/libimobiledevice/libirecovery",
  "remotezip" => "https://pypi.org/project/remotezip/"
);

foreach($dependencies as $dependency_cmd => $dependency_source)
{
  $dependency_available = is_installed($dependency_cmd);

  if($dependency_cmd === 'remotezip')
  {
    define("HAS_REMOTEZIP", $dependency_available);
    if(!HAS_REMOTEZIP)
    {
      echo("remotezip is a python library that allows you to download files from ipsw without downloading the entire ipsw. It's not necessary but it'll save time and resources\n");
      // since it's an optional dependency, skip over it even if it doesn't exist
      continue;
    }
  }

  if(!$dependency_available)
  {
    // attempt to automatically build the dependency
    $dependency_function = "add_dependency_$dependency_cmd";
    if(is_callable($dependency_function))
    {
      $dependency_function();
      if(is_installed($dependency_cmd)) continue;
    }
    echo(pls_install_msg($dependency_source));
    if($dependency_cmd === 'irecovery')
    {
      info("You can also install it with brew install libimobiledevice or something if you're lazy");
    }
    die();
  }
}

define("HAS_WGET",is_installed("wget"));
verbinfo("Cool, you've got everything installed");


// info("Creating & entering working directory if it doesn't exist: " . WD);
if(!is_dir(WD)) {mkdir(WD);}
chdir(WD);

if(!file_exists("BuildManifest.plist")){
  $url = get_ipsw_url(DEVICE, VERSION);
  if(!$url) exit(-1);
  if(HAS_REMOTEZIP)
  {
    verbinfo("Attempting to get files with remotezip");
    if(!remotezip_get_files($url, BOARDCONFIG))
    {
      define("FWFILE",DEVICE . "_" . VERSION . ".ipsw");
      info("Downloading ipsw (it will continue/just finish if it's already (partially) there)");
      if(HAS_WGET){
        execute("wget -q --show-progress --progress=bar:force \"" . addslashes($url) . "\" -cO " . FWFILE . "");
      } else {
        execute("curl \"" . addslashes($url) . "\" -o " . FWFILE . " -C -");
      }
      info("Extracting ipsw");
      execute("unzip " . FWFILE,1);
    }
    else
    {
      verbinfo("remotezip seems to have downloaded all files");
    }
  }
} else {
  verbinfo("BuildManifest.plist already exists, not attempting to resume ipsw download and extraction");
}

info("Finding ramdisk and trustcache name by trying to convert and mount them");
$ramdiskexisted = false;
if(file_exists("ramdisk.dmg")){
  $ramdiskexisted = true;
  verbinfo("ramdisk.dmg already exists, renaming it as we still need to know its original name");
  if(file_exists("ramdisk.dmg.orig")){die("ramdisk.dmg.orig exists, please only run one instance of this program at once or delete ramdisk.dmg.orig" . PHP_EOL);}
  rename("ramdisk.dmg","ramdisk.dmg.orig");
  if(!file_exists("ramdisk.dmg.orig")){die("rename failed" . PHP_EOL);}
}
$dmgs = Array();
$sizes = Array();
foreach(glob("*.dmg") as $dmg){
  $dmgs[$sizes[] = filesize($dmg)] = $dmg;
}
sort($sizes);
for($i = 0; $i<sizeof($sizes);$i++){
  $maybe = $dmgs[$sizes[$i]];
  verbinfo("trying to convert {$maybe} to .dmg");
  $try = execute("img4 -i {$maybe} -o ramdisk.dmg 2>&1",1);
  if($try === "rdsk\n"){
    $mount_point = mount_rd();
    verbinfo("Mounted potential ramdisk at \"{$mount_point}\"");
    verbinfo("Unmounting again because we don't need it right now");
    unmount($mount_point);
    if(strpos($mount_point,"CustomerRamDisk") !== false){
      info("Found ramdisk to be {$maybe}");
      define("RAMDISK",$maybe);
      break;
    } else {
      verbinfo("{$maybe} can't be it, because it mounts wrong");
      @unlink("ramdisk.dmg");
    }
  } else {
    verbinfo("{$maybe} can't be it, because img4 doesn't want to convert it");
    @unlink("ramdisk.dmg");
  }
}
if($ramdiskexisted){
  unlink("ramdisk.dmg");
  rename("ramdisk.dmg.orig","ramdisk.dmg");
}
if(!defined("RAMDISK")){die("Couldn't find ramdisk\n");}



info("Beginning to patch and sign things");
if(!file_exists("kcache.raw")){
  execute("img4 -i kernelcache.* -o kcache.raw");
} else {
  verbinfo("kcache.raw already exists, not converting kernelcache");
}
if(!file_exists("kcache.patched")){
  execute("Kernel64Patcher kcache.raw kcache.patched -a");
} else {
  verbinfo("kcache.patched already exists, skipping step");
}
if(!file_exists("IM4M")){
  execute("img4tool -e -s " . SHSH2_ABS . " -m IM4M");
} else {
  verbinfo("IM4M already exists, skipping conversion of .shsh2 into it");
}
if(!file_exists("kernelcache.im4p")){
  execute("img4tool -c kernelcache.im4p -t rkrn kcache.patched --compression complzss");
} else {
  verbinfo("kernelcache.im4p already exists, not compressing kernel again");
}
if(!file_exists("kernelcache.img4")){
  execute("img4tool -c kernelcache.img4 -p kernelcache.im4p -m IM4M");
} else {
  verbinfo("kernelcache.img4 already exists, skipping packing into .img4");
}
$ibss = glob("iBSS.*.RELEASE.bin");
if(sizeof($ibss) == 0){
  execute("autodecrypt -f iBSS -i " . VERSION . " -d " . DEVICE);
} else {
  verbinfo("Some file matching the pattern iBSS.*.RELEASE.bin already exists, skipping iBSS downloading and decrypting");
}
if(!file_exists("iBSS.patched")){
  execute("kairos iBSS.*.RELEASE.bin iBSS.patched");
} else {
  verbinfo("iBSS.patched already exists, not patching");
}
$ibec = glob("iBEC.*.RELEASE.bin");
if(sizeof($ibec) == 0){
  execute("autodecrypt -f iBEC -i " . VERSION . " -d " . DEVICE);
} else {
  verbinfo("Some file matching the pattern iBEC.*.RELEASE.im4p already exists, skipping iBEC downloading and decrypting");
}
if(!file_exists("iBEC.patched")){
  execute("kairos iBEC.*.RELEASE.bin iBEC.patched -b \"rd=md0 -v\"");
} else {
  verbinfo("iBEC.patched already exists, not patching");
}
if(!file_exists("iBSS.img4")){
  execute("img4 -i iBSS.patched -o iBSS.img4 -M IM4M -A -T ibss");
} else {
  verbinfo("iBSS.patched already exists, not signing patched iBSS");
}
if(!file_exists("iBEC.img4")){
  execute("img4 -i iBEC.patched -o iBEC.img4 -M IM4M -A -T ibec");
} else {
  verbinfo("iBEC.patched already exists, not signing patched iBEC");
}
if(!file_exists("trustcache.img4")){
  execute("img4 -i Firmware/" . RAMDISK .".trustcache -o trustcache.img4 -M IM4M");
} else {
  verbinfo("trustcache.img4 already exists, not signing");
}
if(!file_exists("devicetree.img4")){
  $orig_devicetree = "Firmware/all_flash/DeviceTree." . strtolower(BOARDCONFIG) . ".im4p";
  if(!file_exists($orig_devicetree)){
    info("Couldn't find devicetree at {$orig_devicetree}, selecting closest other one");
    foreach(glob("Firmware/all_flash/DeviceTree.*.im4p") as $devicetree){
      $potential_devicetrees[$devicetree] = levenshtein($devicetree,BOARDCONFIG);
    }
    $orig_devicetree = array_search(min($potential_devicetrees),$potential_devicetrees);
    info("Selected {$orig_devicetree} instead");
  }
  execute("img4 -i {$orig_devicetree} -o devicetree.img4 -M IM4M -T rdtr");
} else {
  verbinfo("devicetree.img4 already exists, skipping singing");
}



info("We now have everything needed to boot the ramdisk, just need to customize the ramdisk!");

if(ask("I'm about to add utilities and modify the ramdisk.\nHave you made manual changes to ramdisk.dmg and only want me to sign it? Enter y.\nFor default just press enter ")) {
  info("Not adding utilities, just signing");
}
else {
  verbinfo("enlarging ramdisk");
  execute("hdiutil resize -size 150MB ramdisk.dmg");
  $mountpoint = mount_rd();
  info("Mounted RAMdisk at {$mountpoint} and now adding tools. Downloading may take a while");
  execute("xcrun -sdk iphoneos clang -arch arm64 " . dirname(__FILE__) . "/resources/restored_external.c -o restored_external");
  execute(LDID2_PATH." -S restored_external");
  verbinfo("compiled restored_external");
  if(!file_exists("{$mountpoint}/usr/local/bin/restored_external_original")){
    verbinfo("renaming old one");
    execute("mv -v \"{$mountpoint}/usr/local/bin/restored_external\" \"{$mountpoint}/usr/local/bin/restored_external_original\"",1);
  }
  verbinfo("adding new one");
  execute("cp -v restored_external \"{$mountpoint}/usr/local/bin/restored_external\"");
  verbinfo("Downloading executables");
  $package_dir = __DIR__."/debs/";
  if(!is_dir($package_dir)){mkdir($package_dir);}
  chdir($package_dir);
  // apt download --print-uris inetutils ncurses ncurses5-libs readline coreutils-bin firmware-sbin system-cmds nano bash sed grep htop findutils less coreutils profile.d com.bingner.snappy
  $apt_download_output = "'https://apt.bingner.com/debs/1443.00/bash_5.0.3-2_iphoneos-arm.deb' bash_5.0.3-2_iphoneos-arm.deb 480482 SHA256:078a0a6dc0619dc5db2cbb411a925ab5c08810279994714fd0343fc63f7d4072
'https://apt.bingner.com/debs/1443.00/com.bingner.snappy_1.3.0_iphoneos-arm.deb' com.bingner.snappy_1.3.0_iphoneos-arm.deb 15438 SHA256:7e9db38dd7959de4484ee686c4cf8e31c47362c43865ae2e0466af190a49d484
'https://apt.bingner.com/debs/1443.00/coreutils_8.31-1_iphoneos-arm.deb' coreutils_8.31-1_iphoneos-arm.deb 714854 SHA256:37a125683866d6afa27979f39ccad3d1e2b187c33f09a9cc291c9e9b1f14a006
'https://apt.bingner.com/debs/1443.00/coreutils-bin_8.31-1_iphoneos-arm.deb' coreutils-bin_8.31-1_iphoneos-arm.deb 679670 SHA256:b4625d37d45684317766ec4c101dc37d6a750851defeb0a175c9d9f3be7f9728
'https://apt.bingner.com/debs/1443.00/findutils_4.6.0-2_iphoneos-arm.deb' findutils_4.6.0-2_iphoneos-arm.deb 212748 SHA256:fb683abb9c0c7ca3b6bcb3aa87ce31785ad2480fd46e650c2fc757a22fa51516
'https://apt.bingner.com/debs/1443.00/firmware-sbin_0-1_iphoneos-all.deb' firmware-sbin_0-1_all.deb 2050 SHA256:ab782faee7925d2702467b7a837ea8755445589de2a3eb8e6b8bd52e6e2d2440
'https://apt.bingner.com/debs/1443.00/grep_3.1-1_iphoneos-arm.deb' grep_3.1-1_iphoneos-arm.deb 91310 SHA256:b2b9473623f867735d878ee12b76af26084bbcf7bf5788a8985a579d8b806bbe
'https://apt.bingner.com/debs/1443.00/htop_2.2.0-1_iphoneos-arm.deb' htop_2.2.0-1_iphoneos-arm.deb 47260 SHA256:56c85b1f968912ab7c85b2561923c0231b1493b6899842552a7da1889d0520f3
'https://apt.bingner.com/debs/1443.00/inetutils_1.9.4-2_iphoneos-arm.deb' inetutils_1.9.4-2_iphoneos-arm.deb 268236 SHA256:4fc0c494701bdb6fa4538788c77d63024960308cc657b72a9e8cc9c09bf9019d
'https://apt.bingner.com/debs/1443.00/less_530-2_iphoneos-arm.deb' less_530-2_iphoneos-arm.deb 73896 SHA256:71b2db9247ca5342c635d5f95bc43f56d09af2d42a6f5602144f6ec37ee6a5a0
'https://apt.bingner.com/debs/1443.00/nano_4.5-1_iphoneos-arm.deb' nano_4.5-1_iphoneos-arm.deb 174454 SHA256:a174f328475b3c926c4074880cc1535873ed5ebb1b303c4aeeba38d4108306a9
'https://apt.bingner.com/debs/1443.00/ncurses_6.1+20181013-1_iphoneos-arm.deb' ncurses_6.1+20181013-1_iphoneos-arm.deb 365394 SHA256:e55c55c9d61f3a3b0c1fd1a3df1add4083a6d3e891f87fb698c57d33d2365567
'https://apt.bingner.com/debs/1443.00/ncurses5-libs_5.9-1_iphoneos-arm.deb' ncurses5-libs_5.9-1_iphoneos-arm.deb 174740 SHA256:3001282a457fc30ea5e79b9a13fcd2f972640e2d78880d5099c4f5e2de484225
'https://apt.bingner.com/debs/1443.00/profile.d_0-1_iphoneos-arm.deb' profile.d_0-1_iphoneos-arm.deb 918 SHA256:c83306dc925b5a7844098a496b8fd3eb1c3787dea912b6f7c4611d64fc651194
'https://apt.bingner.com/debs/1443.00/readline_8.0-1_iphoneos-arm.deb' readline_8.0-1_iphoneos-arm.deb 129432 SHA256:60b71efee41f78b7c427fc575c544a6ea573e4bf07520099a579e2855a040ae4
'https://apt.bingner.com/debs/1443.00/sed_4.5-1_iphoneos-arm.deb' sed_4.5-1_iphoneos-arm.deb 76688 SHA256:8a8e92b8e611514f163ca56e6fd05cbcd9f457f07d4d87ccc9202a1c6b157422
'https://apt.bingner.com/debs/1443.00/system-cmds_790.30.1-2_iphoneos-arm.deb' system-cmds_790.30.1-2_iphoneos-arm.deb 94086 SHA256:5d657d85f7e57452b76037b78e1bd0ae64421309d7c1f43cff22a6e42d3adeaf";
  foreach(explode("\n",$apt_download_output) as $line){
    $url = explode("'",$line)[1];
    if(HAS_WGET){
      execute("wget --trust-server-names -c \"{$url}\"",1);
    } else {
      execute("curl -C - -O \"{$url}\"");
    }
  }
  $debs = glob("*.deb");
  verbinfo("Extracting debs " . implode(", ",$debs));
  if(!is_dir("staging")){
    mkdir("staging");
  }
  foreach($debs as $deb){
    $debdir = $deb . ".dir";
    if(!is_dir($debdir)){
      mkdir($debdir);
    }
    chdir($debdir);
    execute("ar -vx ../{$deb}",1);
    execute("tar -C ../staging/ -xzvkf data.*",1);
    chdir("..");
  }
  verbinfo("Downloading and installing busybox (if Sam Bingner) still hosts it");
  if(HAS_WGET){
    execute("wget https://www.bingner.com/busybox.gz -c -O busybox.gz");
  } else {
    execute("curl https://www.bingner.com/busybox.gz -C - -o busybox.gz");
  }
  execute("cat busybox.gz|gzip -d > busybox");
  execute("chmod +xxx busybox");
  execute(LDID2_PATH." -S busybox");
  execute(LDID2_PATH." -S ./staging/usr/bin/htop");
  execute(LDID2_PATH." -S ./staging/usr/bin/find"); // idk if this makes them not die with Killed: 9 and why they do it otherwise
  execute("cp -v busybox ./staging/bin/");
  verbinfo("Syncing extracted debs into fs");
  execute("rsync --ignore-existing -avhuK --progress ./staging/ \"{$mountpoint}/\""); // this is necessary because tar overwrites symlinks smh
  chdir(__DIR__ . '/' . WD);
  foreach(Array("/var/root","/var/run") as $extra_directory){
    $dir = $mountpoint . $extra_directory;
    if(!is_dir($dir)){
      mkdir($dir);
    }
  }
  foreach(glob(dirname(__FILE__) . "/resources/etc/*") as $etcfile){
    $to = $mountpoint . "/etc/" . basename($etcfile);
    verbinfo("copying {$etcfile} to {$to}");
    execute("cp -v \"{$etcfile}\" \"{$to}\"",1);
  }
  // execute("img4tool -c sep-firmware.img4 -p Firmware/all_flash/sep-firmware.d211.RELEASE.im4p -m IM4M"); // this will 99% never work, leaving it here as legacy. A missing sep-firmware.img4 can't be recovered.
  // execute("cp -v sep-firmware.img4 {$mountpoint}/usr/standalone/firmware/");
  unmount($mountpoint);
}
execute("img4 -i ramdisk.dmg -o ramdisk -M IM4M -A -T rdsk");
$bootrd = dirname(__FILE__) . "/bootrd_" . VERSION . ".sh";
info("All done, writing bootscript to bootrd.sh. Execute ./bootrd_" . VERSION . ".sh to boot.");
if(!file_exists("../PyBoot/pyboot.py")){
  info("You have not successfully cloned pyboot, doing it for you.");
  chdir("../PyBoot/");
  if(HAS_WGET){
    execute("wget -c https://github.com/MatthewPierson/PyBoot/archive/master.zip",1);
  } else {
    execute("curl -C - -o master.zip https://github.com/MatthewPierson/PyBoot/archive/master.zip",1);
  }
  execute("unzip master.zip",1);
  execute("mv -v PyBoot-master/* .",1);
  execute("rm -rvf PyBoot-master",1);
  unlink("master.zip");
  info("Try cloning with --recursive next time.");
}
file_put_contents($bootrd,"#!/usr/bin/env bash
cd PyBoot
./pyboot.py -p
cd ../" . WD . "
sleep 1
irecovery -f iBSS.img4
sleep 1
irecovery -f iBSS.img4
sleep 1
irecovery -f iBEC.img4
sleep 1
irecovery -c go
sleep 1
irecovery -f ramdisk
sleep 1
irecovery -c ramdisk
sleep 1
irecovery -f devicetree.img4
sleep 1
irecovery -c devicetree
sleep 1
irecovery -f trustcache.img4
sleep 1
irecovery -c firmware
sleep 1
irecovery -f kernelcache.img4
sleep 1
irecovery -c bootx
echo Alright, now run 'iproxy 2323 23' to start the proxy, and in a new tab connect with 'telnet 127.0.0.1 2323' as soon as the screen turns white. Login with username: root, password: alpine. Screen will get black after like two minutes but you should still have connectivity.");
execute("chmod -v +xxx {$bootrd}",1);
if(ask("Cool, now pray it worked. Enter yes to boot directly (you need to be in DFU mode): ")){
  chdir(dirname(__FILE__));
  execute($bootrd);
}
if(!VERBOSE){
  fwrite(LOG,"telnet_rd_end|" . date("c") . PHP_EOL);
  fclose(LOG);
}


?>
