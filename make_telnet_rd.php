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

// check args and usage
for($i = 1; $i < 5; $i++){
  if(!isset($argv[$i])){
    die("Usage: php {$argv[0]} <device> <\"boardconfig\"> <version> <.shsh2 file>\nexample:\n{$argv[0]} iPhone10,6 D221AP 13.0 13.0.shsh2\nwhat's this? this thing takes you from your phone to a booted telnet ramdisk\n.shsh2 file can be from any version but needs to be from your device (ecid). Use: https://shsh.host/\n");
  }
}
foreach(Array("device","boardconfig","version","shsh2") as $i => $arg){
  define(strtoupper($arg),$argv[$i+1]);
}
define("WD",DEVICE . "-" . BOARDCONFIG . "-" . VERSION . "_telnet_rd");

verbinfo("checking for .shsh2 file");
if(!file_exists(SHSH2)){
  die(".shsh2 file doesn't exist or isn't readable" . PHP_EOL);
}
define("SHSH2_ABS",realpath(SHSH2)); // I shouldn't have started using constants lol

verbinfo("Checking for dependencies");
if(!is_installed("diskutil") || !is_installed("hdiutil") || !is_installed("unzip") || !is_installed("plutil")){
  die("Couldn't find diskutil, unzip, plutil or hdiutil. Please use a mac." . PHP_EOL);
}
if(!is_installed("img4")){
  die(pls_install_msg("https://github.com/xerub/img4lib"));
}
if(!is_installed("img4tool")){
  die(pls_install_msg("https://github.com/tihmstar/img4tool"));
}
if(!is_installed("ldid2")){
  die(pls_install_msg("https://github.com/xerub/ldid/releases/"));
}
if(!is_installed("autodecrypt")){
  die(pls_install_msg("https://github.com/matteyeux/autodecrypt"));
}
if(!is_installed("kairos")){
  die(pls_install_msg("https://github.com/dayt0n/kairos"));
}
if(!is_installed("Kernel64Patcher")){
  die(pls_install_msg("https://github.com/Ralph0045/Kernel64Patcher"));
}
if(!is_installed("irecovery")){
  echo(pls_install_msg("https://github.com/libimobiledevice/libirecovery"));
  info("You can also install it with brew install libimobiledevice or something if you're lazy");
  exit;
}
verbinfo("Cool, you've got everything installed");


// info("Creating & entering working directory if it doesn't exist: " . WD);
if(!is_dir(WD)) {mkdir(WD);}
chdir(WD);

if(!file_exists("BuildManifest.plist")){
  define("FWFILE",DEVICE . "_" . VERSION . ".ipsw");
  info("Downloading ipsw (it will continue/just finish if it's already (partially) there)");
  $url = get_ipsw_url();
  execute("curl \"" . addslashes($url) . "\" -o " . FWFILE . " -C -");
  info("Extracting ipsw");
  execute("unzip " . FWFILE);
} else {
  verbinfo("BuildManifest.plist already exists, not attempting to resume ipsw download and extraction");
}

info("Finding ramdisk and trustcache name by trying to convert and moint them");
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
  $try = execute("img4 -i {$maybe} -o ramdisk.dmg 2>&1");
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





info("Everything is now known, beginning to do the magic");
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

if(ask("I'm about to add utilities and modify the ramdisk. Have you made manual changes to ramdisk.dmg and only want me to sign it? Enter y, otherwise n: ")) {
  info("Not adding utilities, just signing");
  execute("img4 -i ramdisk.dmg -o ramdisk -M IM4M -A -T rdsk");
}
else {
  info("Doing magic");
  $mountpoint = mount_rd();
  info("Mounted RAMdisk at {$mountpoint}");
  ensure_restored_external_c();
  execute("xcrun -sdk iphoneos clang -arch arm64 restored_external.c -o restored_external");
  execute("ldid2 -S restored_external");
  verbinfo("compiled restored_external, renaming old one");
  rename($mountpoint . "/usr/local/bin/restored_external",$mountpoint . "/usr/local/bin/restored_external_original");
  verbinfo("adding new one");
  copy("restored_external",$mountpoint . "/usr/local/bin/restored_external");
  // apt download --print-uris inetutils ncurses ncurses5-libs readline coreutils-bin
  verbinfo("Downloading executables");
  if(!is_dir("debs")){mkdir("debs");}
  chdir("debs");
  $apt_download_output = "'https://apt.bingner.com/debs/1443.00/coreutils-bin_8.31-1_iphoneos-arm.deb' coreutils-bin_8.31-1_iphoneos-arm.deb 679670 SHA256:b4625d37d45684317766ec4c101dc37d6a750851defeb0a175c9d9f3be7f9728
'https://apt.bingner.com/debs/1443.00/inetutils_1.9.4-2_iphoneos-arm.deb' inetutils_1.9.4-2_iphoneos-arm.deb 268236 SHA256:4fc0c494701bdb6fa4538788c77d63024960308cc657b72a9e8cc9c09bf9019d
'https://apt.bingner.com/debs/1443.00/ncurses_6.1+20181013-1_iphoneos-arm.deb' ncurses_6.1+20181013-1_iphoneos-arm.deb 365394 SHA256:e55c55c9d61f3a3b0c1fd1a3df1add4083a6d3e891f87fb698c57d33d2365567
'https://apt.bingner.com/debs/1443.00/ncurses5-libs_5.9-1_iphoneos-arm.deb' ncurses5-libs_5.9-1_iphoneos-arm.deb 174740 SHA256:3001282a457fc30ea5e79b9a13fcd2f972640e2d78880d5099c4f5e2de484225
'https://apt.bingner.com/debs/1443.00/readline_8.0-1_iphoneos-arm.deb' readline_8.0-1_iphoneos-arm.deb 129432 SHA256:60b71efee41f78b7c427fc575c544a6ea573e4bf07520099a579e2855a040ae4";
foreach(explode("\n",$apt_download_output) as $line){
  $url = explode("'",$line)[1];
  execute("curl -O \"{$url}\"");
}
  unmount($mountpoint);
}


function ask($question){
  info("\e[1m\x1B[31m{$question}\e[0m\e[0m",false);
  $h = fopen("php://stdin","r");
  $answer = fgets($h);
  fclose($h);
  return ($answer === "y" . PHP_EOL || $answer === "yes" . PHP_EOL);
}
function mount_rd(){
  verbinfo("Mounting ramdisk.dmg");
  $try_mount = execute("hdiutil attach ramdisk.dmg 2>&1");
  $mount_point = explode(" ",preg_replace('/\s+/', ' ', $try_mount));
  unset($mount_point[0]);
  unset($mount_point[sizeof($mount_point)]);
  $mount_point = implode(" ",$mount_point);
  return $mount_point;
}
function unmount($mountpoint){
  return execute("diskutil unmount \"{$mountpoint}\" 2>&1");
}
function execute($command){
  verbinfo("Executing {$command}");
  $h = popen($command . " 2>&1","r");
  $o = "";
  while (!feof($h)) {
    $b = fread($h,1024);
    $o .= $b;
    echo $b;
  }
  fclose($h);
  return $o;
}
function get_ipsw_url(){
  verbinfo("Getting ipsw url from ipsw.me API");
  $versions = json_decode(file_get_contents("https://api.ipsw.me/v4/device/" . DEVICE . "?type=ipsw"),1)['firmwares'];
  if(VERBOSE){var_dump($versions);}
  $url = "";
  foreach($versions as $version){
    if($version['version'] == VERSION){ // lol
      $url = $version["url"];
      break;
    }
  }
  if(strlen($url) < 1){die("Couldn't find version " . VERSION . " for " . DEVICE . PHP_EOL);}
  verbinfo("Got url {$url}");
  return $url;
}
function info($msg,$newline = true){
  echo $GLOBALS['argv'][0] . ": {$msg}" . ($newline ? PHP_EOL : "");
}
function verbinfo($msg){
  if(VERBOSE){
    info($msg);
  }
}
function pls_install_msg($github_project){
  return "Dependency missing: Please download, compile (if needed) and install {$github_project} and make sure it's in PATH." . PHP_EOL;
}
function is_installed($cmd){
  verbinfo("Checking for {$cmd}");
  if(shell_exec("which {$cmd}") === NULL){
    return false;
  } else {
    return true;
  }
}
function ensure_restored_external_c(){
  if(!file_exists("restored_external.c")){
    verbinfo("writing sample restored_external.c");
    file_put_contents("restored_external.c",'
#import <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

int main(){
    sleep(5);
    printf("****************************************************\n");
    printf("****************************************************\n");
    printf("****************Starting inetd and USB magic***************\n");
    printf("****************************************************\n");
    printf("****************************************************\n");
    int pid, i;
    char *arg[] = {"inetd","/private/etc/inetd.conf", NULL};
    posix_spawn(&pid, "/usr/libexec/inetd",NULL, NULL, (char* const*)arg, NULL);
    waitpid(pid, &i, 0);
    char *arg12[] = {"restored_external",NULL};
    posix_spawn(&pid, "/usr/local/bin/restored_external_original",NULL, NULL, (char* const*)arg12, NULL);
    waitpid(pid, &i, 0);
    return 0;
}');
  }
}
?>
