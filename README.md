# telnetd_ramdisk
### An automatic telnet ramdisk creator

Remember [ssh_rd by msftguy](https://github.com/msftguy/ssh-rd)? You could simply run it and get a remote shell running on a ramdisk on your iDevice. I built a jailbreak with it. (And opensn0w).
This is the same thing, reloaded. I couldn't get sshd to work, so it uses inetd with telnetd instead. If you check the code there's an easy way to add whatever .deb you like by rerunning the apt-download command and pasting the result in the code. (Search the code for `apt download`)

## Usage

To download/install:

`git clone https://github.com/danieltroger/telnetd_ramdisk.git --recursive`

```
OPTIONS:
  -d	Device identifier (example: iPhone10,4)
  -b	Boardconfig (example: d201ap)
  -v	iOS version to use as base for ramdisk
  -s	shsh2 file (can be any version)
  -h	print this help text

```


Example: `./make_telnet_rd.php -d iPhone10,5 -b D211AP -v 13.5 -s /Users/daniel/Documents/dualbootfun/4905935052021678_iPhone10\,5_d211ap_13.7-17H35_27325c8258be46e69d9ee57fa9a8fbc28b873df434e5e702a8b27999551138ae.shsh2`

To boot a previously created ramdisk, use the ./bootrd*.sh scripts

It will open port 23 on the device once it's booted which you need to "proxy" over usb with iproxy
Just follow the instructions of the script :)

To enter dfu mode [look here](https://www.theiphonewiki.com/wiki/DFU_Mode) or use [checkra1n](https://checkra.in/) and QUIT IT ASAP as soon as it says "successfully entered dfu mode"

#### More info
For getting the .shsh2 files I can recommend [shsh.host](https://shsh.host). The version of the blobs *DON'T MATTER*, they just have to be for the correct device (ECID). So you can boot a 13.0 ramdisk with 13.7 blobs.

it tells you everything you need to know and do to get the ramdisk and telnet connection up and running. PyBoot is used for booting, so the supported devices (as of now) are:
* iPhone 5s
* iPhone 6/6+
* iPhone 6s/6s+
* iPhone SE (First Gen)
* iPhone 7/7+
* iPhone 8/8+
* iPhone X

Supported operating systems: macOS

Regarding iOS versions your mileage may vary. I have successfully tested iOS 12.0, 12.4.1, 13.0, 13.5, 13.6, 13.7, 14.0.

BTW: the whole script is made to be ran again and again. Cancelled half way? Run it again and it will pick up where it left. Made changes to ramdisk.dmg? Run it again and answer yes. Made changes to restored_external.c? Run it again! Don't be afraid


The script is based on [this guide](https://dualbootfun.github.io/) and roughly does this:
1. Checks for dependencies
2. Downloads ipsw
3. Extracts ipsw
4. Identifies trustcache/ramdisk names by mounting all dmgs and keeping the name of the one with the correct partition name
5. Downloads and patches iBSS, iBEC and kernelcache, signs them + trustcache & devicetree
6. Downloads .debs to install into the ramdisk
7. Extracts those debs to a staging area
8. Syncs the staging area into the ramdisk which it mounts (this is done to preserve symlinks and not override what exists)
9. Adds inetd and other config files to /etc
10. Compiles and signs a binary which will start telnetd and attempt to mount the rootfs and data fs with seputil
11. Downloads pyboot in case you didn't clone this repo recursively
12. Writes a shell script to boot with pyboot and load all needed files

You can then execute that script by running `./boot_rd_VERSION.sh` with VERSION being the ios version

### What's expected to happen?
- the script will output the output of all its sub-utilities. Read it, in case some errors out.
- when running bootrd.sh:
- PyBoot should say "exploit worked", at least for A11 devices if everything went right
- irecovery should show a total of six progress bars, and while they load the backlight should turn on and off on non OLED devices
- after all 6 progress bars have loaded you should see a verbose boot on the device's screen
- read the verbose boot output, especially at the end, for hints of errors
- after the verbose boot the screen should turn white with a progress bar under an apple logo which never completes. At this point (or like 10s after) you can start iproxy if you haven't already and try to connect via telnet
- after around a minute the screen will turn black but you should still have access to the device


#### Dependencies:
img4lib, img4tool, ldid2, autodecrypt, kairos, libusbmuxd, Kernel64Patcher, libirecovery and tools preinstalled on macOS (like php, curl, zip, plutil, hdiutil, etc). If you just run the script without them it will give you the github URLs to install them from.

## Please use this shit. Open an issue if you need help. I don't want to have wasted the 2 days this took writing for using it once.


-------

# Credits

* @arx8x for his contributions of bug fixes, improvements and a big speed up to the project and [his fork](https://github.com/arx8x/telnetd_ramdisk) which I shamelessly merged into my repo
* Huge thanks to [mcg29](https://github.com/mcg29), without his help and info I would have given up a long time ago. [twitter](https://twitter.com/mcg29_)
* Also a big thanks to [Exploit3d](https://twitter.com/exploit3dguy) for the info about the seputil commands, mounting the user fs would have been hard without his help.
* Thanks to [Sam Bingner](https://github.com/sbingner) for the inetutils package, we wouldn't have telnetd on iOS without him. Also he inspired me to write script this by asking for it. [twitter](https://twitter.com/sbingner)
* Thanks to [@MatthewPierson](https://github.com/MatthewPierson) for PyBoot and [@axi0mX](https://github.com/axi0mX) for [ipwndfu](https://github.com/axi0mX/ipwndfu)/checkm8 I'm illiterate in low-level stuff so all the peeps making this "a bunch of shell commands" are incredible. Thank you <3!
* Thanks to the writers of my dependencies:
  * [@xerub](https://github.com/xerub) for img4lib
  * [@dayt0n](https://github.com/dayt0n) for kairos
  * [@nikias](https://github.com/nikias) / [@libimobiledevice](https://github.com/libimobiledevice) for iproxy and libirecovery
  * Thanks to [@Ralph0045](https://github.com/Ralph0045) for Kernel64Patcher
* And thanks to whoever I might have forgotten

the script is licensed under GPLv3
