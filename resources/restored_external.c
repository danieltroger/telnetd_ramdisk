#import <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

int main(){
    sleep(5);
    printf("****************************************************\n");
    printf("****************************************************\n");
    printf("*************Starting inetd and USB magic***********\n");
    printf("****************************************************\n");
    printf("****************************************************\n");
    int pid, i;
    char *arg[] = {"inetd","/private/etc/inetd.conf", NULL};
    posix_spawn(&pid, "/usr/libexec/inetd",NULL, NULL, (char* const*)arg, NULL);
    waitpid(pid, &i, 0);
    char *arg2[] = {"restored_external",NULL};
    posix_spawn(&pid, "/usr/local/bin/restored_external_original",NULL, NULL, (char* const*)arg2, NULL);
    waitpid(pid, &i, 0);
    return 0;
}
