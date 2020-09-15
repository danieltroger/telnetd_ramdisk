#import <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

int main(){
    sleep(8);
    printf("****************************************************\n");
    printf("****************************************************\n");
    printf("*************Starting inetd and USB magic***********\n");
    printf("****************************************************\n");
    printf("****************************************************\n");
    int pid, i;
    char *arg[] = {"mount_apfs","/dev/disk0s1s1","/mnt1", NULL}; // rootfs, may be empty or not. We attempt all this to stop restored_external from trying it itself and rebooting when it fucks up
    posix_spawn(&pid, "/System/Library/Filesystems/apfs.fs/mount_apfs",NULL, NULL, (char* const*)arg, NULL);
    waitpid(pid, &i, 0);
    char *arg2[] = {"mount_apfs","-R","/dev/disk0s1s3","/mnt7", NULL}; // XATTR volume, if sep-firmware is present this will be used to make data (user partition) mountable
    posix_spawn(&pid, "/System/Library/Filesystems/apfs.fs/mount_apfs",NULL, NULL, (char* const*)arg2, NULL);
    waitpid(pid, &i, 0);
    char *arg3[] = {"seputil","--gigalocker-init", NULL};
    posix_spawn(&pid, "/usr/libexec/seputil",NULL, NULL, (char* const*)arg3, NULL);
    waitpid(pid, &i, 0);
    char *arg4[] = {"bash","-c","/usr/libexec/seputil --load /mnt1/usr/standalone/firmware/sep-firmware.img4 &", NULL}; // let seputil load shit, but do it in bg because if it doesn't work it will get stuck otherwise
    posix_spawn(&pid, "/bin/bash",NULL, NULL, (char* const*)arg4, NULL);
    waitpid(pid, &i, 0);
    sleep(5); // wait for above to complete, if it succeeds
    char *arg5[] = {"bash","-c","/System/Library/Filesystems/apfs.fs/mount_apfs /dev/disk0s1s2 /mnt2 &", NULL}; // data partition
    posix_spawn(&pid, "/bin/bash",NULL, NULL, (char* const*)arg5, NULL);
    waitpid(pid, &i, 0);
    char *arg6[] = {"bash","-c","/usr/libexec/inetd -d /private/etc/inetd.conf &", NULL}; // inetd which will (hopefully) start and manage telnetd
    posix_spawn(&pid, "/bin/bash",NULL, NULL, (char* const*)arg6, NULL);
    waitpid(pid, &i, 0);
    printf("\n\n\n\n");
    sleep(5);
    char *arg7[] = {"restored_external","-server",NULL}; // run restored_external in server mode, this initializes usb/networking and enables access over iproxy
    posix_spawn(&pid, "/usr/local/bin/restored_external_original",NULL, NULL, (char* const*)arg7, NULL);
    waitpid(pid, &i, 0);
    sleep(99999);
    return 0;
}
