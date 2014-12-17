/*
@(#)File:           $RCSfile: timeout.c,v $
@(#)Version:        $Revision: 5.1 $
@(#)Last changed:   $Date: 2011/02/12 00:55:52 $
@(#)Purpose:        Run command with timeout monitor
@(#)Author:         J Leffler
@(#)Copyright:      (C) JLSS 1989,1997,2003,2005-11
*/

/*TABSTOP=4*/

#include "posixver.h"
#include <ctype.h>
#include <errno.h>
#include <limits.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>
#include "stderr.h"

/*
** This code uses SIGRTMIN (defined by POSIX, at least with real-time
** extensions) as an indicator of the maximum acceptible signal number.
** MacOS X 10.4.11, for instance, does not define SIGRTMIN.  On MacOS X,
** SIGUSR2 is 31 (it is 17 on Solaris 10 and other SVR4-derived
** systems).  Again, on MacOS X, there is a SIGTHR related to
** terminating threads, defined as 32.  Use SIGUSR2 as a plausible
** maximum signal number where SIGRTMIN is not defined; if SIGUSR2 is
** not defined, simply use 32.
*/
#ifndef SIGRTMIN
#ifdef SIGUSR2
#define SIGRTMIN (SIGUSR2 + 1)
#else
#define SIGRTMIN 32
#endif /* SIGUSR2 */
#endif /* SIGRTMIN */

#define CHILD       0
#define FORKFAIL    -1

static const char optstr[] = "hvVt:s:";
static const char usestr[] = "[-hvV] -t time [-s signal] cmd [arg ...]";
static const char hlpstr[] =
    "  -V         Print version and exit\n"
    "  -h         Print help and exit\n"
    "  -v         Verbose output\n"
    "  -t time    Timeout (in seconds: 2m = 2 minutes; 3h = 3 hours, 4d = 4 days)\n"
    "  -s signal  Signal to send to process (default SIGTERM)\n"
    ;

#ifndef lint
/* Prevent over-aggressive optimizers from eliminating ID string */
const char jlss_id_timeout_c[] = "@(#)$Id: timeout.c,v 5.1 2011/02/12 00:55:52 jleffler Exp $";
#endif /* lint */

static int eval_timeout(const char *arg)
{
    static const struct
    {
        char    letter;
        long    multiplier;
    } units[] =
    {
        { 's',     1 }, /* Seconds */
        { 'm',    60 }, /* Minutes */
        { 'h',  3600 }, /* Hours */
        { 'd', 86400 }, /* Days */
    };
    enum { NUM_UNITS = sizeof(units)/sizeof(units[0]) };
    long  tm_out;
    char *end;

    errno = 0;
    tm_out = strtol(arg, &end, 0);
    if (errno != 0 || tm_out <= 0)
        err_error("Invalid timeout %s (positive number required)\n", arg);
    if (tm_out >= INT_MAX)
        err_error("Timeout %s is too large\n", arg);
    if (*end != '\0')
    {
        int i;
        char letter = *end;
        if (!isalpha(letter))
            err_error("Invalid character '%c' in timeout %s\n", letter, arg);
        letter = tolower(letter);
        for (i = 0; i < NUM_UNITS; i++)
        {
            if (units[i].letter == letter)
            {
                if (tm_out >= INT_MAX / units[i].multiplier)
                    err_error("Timeout %s is too large\n", arg);
                tm_out *= units[i].multiplier;
                break;
            }
        }
        if (i >= NUM_UNITS)
            err_error("Invalid time unit %c (s seconds, m minutes, h hours, d days)\n", letter);
    }

    return tm_out;
}

/*
** On MacOS X, the signal() function is implemented using sigaction()
** and includes SA_RESTART in the options, so that when the alarm times
** out, the signal catcher is called but wait() resumes, which is
** not what's wanted.  Hence, upgrade to use sigaction() directly (when
** available; assume it is available by default) without the SA_RESTART.
*/
static void set_interruptible_alarm_handler(void (*handler)(int))
{
#ifdef NO_SIGACTION
    /* Unlikely to be necessary on modern POSIX systems */
    signal(SIGALRM, catcher);
#else
    struct sigaction act;

    if (sigemptyset(&act.sa_mask) != 0)
        err_syserr("sigemptyset failed: ");
    act.sa_handler = handler;
    act.sa_flags = 0;
    if (sigaction(SIGALRM, &act, 0) != 0)
        err_syserr("failed to set signal handler\n");
#endif /* NO_SIGACTION */
}

static void catcher(int signum)
{
    return;
}

int main(int argc, char **argv)
{
    pid_t   pid;
    int     tm_out;
    int     kill_signal;
    pid_t   corpse;
    int     status;
    int     opt;
    int     vflag = 0;

    err_setarg0(argv[0]);

    opterr = 0;
    tm_out = 0;
    kill_signal = SIGTERM;
    while ((opt = getopt(argc, argv, optstr)) != -1)
    {
        switch(opt)
        {
        case 'h':
            err_help(usestr, hlpstr);
            break;
        case 'V':
            err_version("TIMEOUT", &"@(#)$Revision: 5.1 $ ($Date: 2011/02/12 00:55:52 $)"[4]);
            break;
        case 's':
            kill_signal = atoi(optarg);
            if (kill_signal <= 0 || kill_signal >= SIGRTMIN)
                err_error("signal number must be between 1 and %d\n", SIGRTMIN - 1);
            break;
        case 't':
            tm_out = eval_timeout(optarg);
            break;
        case 'v':
            vflag = 1;
            err_setlogopts(ERR_STAMP);  /* Timestamp each output */
            break;
        default:
            err_usage(usestr);
            break;
        }
    }

    if (optind >= argc || tm_out == 0)
        err_usage(usestr);

    if ((pid = fork()) == FORKFAIL)
        err_syserr("failed to fork\n");
    else if (pid == CHILD)
    {
        execvp(argv[optind], &argv[optind]);
        err_syserr("failed to exec command %s\n", argv[optind]);
    }

    /* Must be parent -- wait for child to die */
    if (vflag)
        err_remark("time %d, signal %d, child PID %u\n", tm_out, kill_signal, (unsigned)pid);
    set_interruptible_alarm_handler(catcher);
    alarm((unsigned int)tm_out);
    while ((corpse = wait(&status)) != pid && errno != ECHILD)
    {
        if (errno == EINTR)
        {
            /* Timed out -- kill child */
            if (vflag)
                err_remark("timed out - send signal %d to process %d\n", (int)kill_signal, (int)pid);
            if (kill(pid, kill_signal) != 0)
                err_syserr("sending signal %d to PID %d - ", kill_signal, (int)pid);
            corpse = wait(&status);
            break;
        }
    }

    alarm(0);
    if (vflag)
    {
        if (corpse == (pid_t) -1)
            err_syserr("no valid PID from waiting - ");
        else
            err_remark("child PID %u status 0x%04X\n", (unsigned)corpse, (unsigned)status);
    }

    if (corpse != pid)
        status = 2; /* Dunno what happened! */
    else if (WIFEXITED(status))
        status = WEXITSTATUS(status);
    else if (WIFSIGNALED(status))
        status = WTERMSIG(status);
    else
        status = 2; /* Dunno what happened! */

    return(status);
}
