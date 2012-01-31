/* Little address resolver tool
 *
 * try: ipof -
 *
 * This Works is placed under the terms of the Copyright Less License,
 * see file COPYRIGHT.CLL.  USE AT OWN RISK, ABSOLUTELY NO WARRANTY.
 */

#define _GNU_SOURCE

#include <stdio.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <stdlib.h>
#include <stdarg.h>
#include <unistd.h>
#include <string.h>
#include <netdb.h>
#include <errno.h>

static int flag_fam = AF_UNSPEC;
static int flag_all = 0;
static int flag_err = -1;
static int flag_verbose = 0;
static int flag_unbuffered = 0;
static int flag_quiet = 0;
static int flag_progress = 0;
static int flag_ext = 0;
static int flag_dupes = 0;
static int flag_eoo = 0;

static void
flush(FILE *fd)
{
  if (flag_unbuffered)
    fflush(fd);
}

static void
progress(char c)
{
  if (!flag_progress)
    return;
  fputc(c, stderr);
  flush(stderr);
}

static void
oops(const char *s, ...)
{
  va_list	list;
  int		e;

  progress('!');
  if (!flag_quiet)
    {
      e	= errno;
      fprintf(stderr, "error: ");
      va_start(list, s);
      vfprintf(stderr, s, list);
      va_end(list);
      fprintf(stderr, ": %s\n", strerror(e));
      flush(stderr);
    }
  flag_err = 1;
}

static void
out(char c)
{
  if (flag_verbose>=0)
    putchar(c);
}

static void
p4(struct sockaddr_in *sa)
{
  unsigned char *s;

  s	= (unsigned char *)&sa->sin_addr.s_addr;
  printf("%d.%d.%d.%d", s[0], s[1], s[2], s[3]);
}

static void
p6(struct sockaddr_in6 *sa)
{
  unsigned char *s;
  int		i, nulls;

  s	= sa->sin6_addr.s6_addr;
  nulls	= -1;
  for (i=0; i<16; i+=2)
    {
      if (nulls>0 || s[i] || s[i+1])
	{
	  if (i && nulls)
	    putchar(':');
	  printf("%x", (((unsigned)s[i])<<8)|s[i+1]);
	  if (nulls>0)
	    nulls++;
	}
      else if (nulls<0)
	{
	  putchar(':');
	  putchar(':');
	  nulls = 0;
	}
    }
}

static void resolve(const char *);
static void usage(const char *);

static void
read_stream(FILE *fd)
{
  char	buf[1024];

  progress('[');
  buf[0] = 0;
  while (fscanf(fd, "%1000s", buf)>0)
    resolve(buf);
  progress(']');
}

static void
read_stdin(const char *ignored)
{
  read_stream(stdin);
}

static void
read_file(const char *name)
{
  FILE	*fd;

  progress('o');
  fd	= fopen(name, "rt");
  if (!fd)
    {
      oops("open error %s", name);
      return;
    }
  read_stream(fd);
  if (fclose(fd))
    oops("close error: %s", name);
}

static void
read_file_o(const char *name)
{
  flag_eoo	= 1;
  if (*name)
    read_file(name);
  else
    read_stream(stdin);
  flag_eoo	= 0;
}

struct cmds
  {
    const char	*help;
    int		*var;
    int		val;
    void	(*fn)(const char *);
  } cmds[] =
  {
    { "h	help",			NULL, 2, usage },
    { "?	help",			NULL, 2, usage },
    { "\0	read stdin",		NULL, 1, read_stdin },
    { "-	end of options",	&flag_eoo, 1 },
    { "-file	read file, options on",	NULL, 0, read_file },
    { "+[fil]	read file, no options",	NULL, 0, read_file_o },
    { "b	buffered",		&flag_unbuffered, 1 },
    { "u	unbuffered",		&flag_unbuffered, 0 },
    { "p	progress",		&flag_progress, 1 },
    { "n	no progress",		&flag_progress, 0 },
    { "r	reset error flag",	&flag_err, -1 },
    { "e	set error flag",	&flag_err, 1 },
    { "o	set ok",		&flag_err, 0 },
    { "q	quiet errors",		&flag_quiet, 1 },
    { "e	errors",		&flag_quiet, 0 },
    { "x	extended info",		&flag_ext, 1 },
    { "i	ip info",		&flag_ext, 0 },
    { "v	verbose",		&flag_verbose, 1 },
    { "s	silent",		&flag_verbose, 0 },
    { "t	totally silent",	&flag_verbose, -1 },
    { "c	clean dupes",		&flag_dupes, 0 },
    { "d	dupes",			&flag_dupes, 1 },
    { "a	show all addresses",	&flag_all, 1 },
    { "1	show single address",	&flag_all, 0 },
    { "0	any address format",	&flag_fam, AF_UNSPEC },
    { "4	IPv4 address format",	&flag_fam, AF_INET },
    { "6	IPv6 address format",	&flag_fam, AF_INET6 },
    { 0 }
  };

static void
usage(const char *ignored)
{
  int	i;

  fprintf(stderr, "Options: --> active\n");
  for (i=0; cmds[i].help; i++)
    {
      if (cmds[i].var && *cmds[i].var==cmds[i].val)
	fprintf(stderr, "-->");
      fprintf(stderr, "\t-%s\n", *cmds[i].help ? cmds[i].help : cmds[i].help+1);
    }
  fflush(stderr);  /* always flush	*/
}

static const char *
strprint(char *buf, size_t max, const char *s, ...)
{
  va_list	list;

  va_start(list, s);
  vsnprintf(buf, max, s, list);
  va_end(list);
  return buf;
}

static const char *
getproto(int proto)
{
  static char	buf[50];
  struct protoent *p = getprotobynumber(proto);
  
  return p ? strprint(buf, sizeof buf, "%s(%d)", p->p_name, proto) : strprint(buf, sizeof buf, "(protocol %d)", proto);
}

struct typemap
  {
    const char *name;
    int id;
  };

static const char *
typemap(int type, struct typemap *map, const char *unknown, char buf[50])
{
  for (; map->name; map++)
    if (map->id==type)
      return strprint(buf, 50, "%s(%d)", map->name, type);
  return strprint(buf, 50, "(%s %d)", unknown, type);
}

static struct typemap socktypes[] =
  {
#define T(X) { #X, SOCK_ ## X }
    T(STREAM),
    T(DGRAM),
    T(SEQPACKET),
    T(RAW),
    T(RDM),
    T(PACKET),
    { 0 }
#undef T
  };

static const char *
getsocktype(int socktype)
{
  static char	buf[50];

  return typemap(socktype, socktypes, "socktype", buf);
}

static struct typemap sockfam[] =
  {
#define T(X) { #X, AF_ ## X }
    T(INET),
    T(INET6),
#undef T
    { 0 }
  };

static const char *
getsockfam(int family)
{
  static char	buf[50];

  return typemap(family, sockfam, "family", buf);
}

static void
resolve(const char *host)
{
  struct addrinfo	*ret, hint, *p, *last;
  int			had, i;

  if (!flag_eoo && host[0]=='-')
    for (i=0; cmds[i].help; i++)
      if (cmds[i].help[0]==host[1])
	{
	  if (cmds[i].var && !host[2])
	    {
	      *cmds[i].var	= cmds[i].val;
	      return;
	    }
	  if (cmds[i].fn && (!cmds[i].val || !host[cmds[i].val]))
	    {
	      cmds[i].fn(host+2);
	      return;
	    }
	}

  memset(&hint, 0, sizeof hint);
  hint.ai_family	= flag_fam;
  hint.ai_flags		= AI_IDN;

  progress('.');
  if (getaddrinfo(host, "1", &hint, &ret))
    {
      progress('x');
      oops("cannot resolve %s", host);
      return;
    }
  had	= 0;
  last	= 0;
  for (p=ret; p; p=p->ai_next)
    {
      void	(*fn)(/*(struct sockaddr *)*/);

      switch (p->ai_family)
	{
	default:
	  progress('?');
	  fprintf(stderr, "unknown socket family: %d\n", p->ai_family);
	  continue;

	case AF_INET:
	  progress('4');
	  fn	= p4;
	  break;
	  
	case AF_INET6:
	  progress('6');
	  fn	= p6;
	  break;
	
	}
      if (!flag_dupes && last && last->ai_addrlen==p->ai_addrlen && !memcmp(last->ai_addr, p->ai_addr, p->ai_addrlen))
	continue;

      if (had++)
	out(' ');
      else if (flag_verbose>0)
	printf("%s ", host);

      if (flag_verbose>=0)
	{
	  if (flag_ext)
	    printf("<%s,%s,%s>", getsockfam(p->ai_family), getsocktype(p->ai_socktype), getproto(p->ai_protocol));
	  fn(p->ai_addr);
	  if (flag_ext && p->ai_canonname)
	    printf("\"%s\"", p->ai_canonname);
	}
      last	= p;

      if (!flag_all)
	break;
    }
  freeaddrinfo(ret);
  if (had)
    {
      out('\n');
      if (flag_err<0)
	flag_err	= 0;
    }
  flush(stdout);
}

int
main(int argc, char **argv)
{
  int	i;

  if (argc<2)
    usage(NULL);
  for (i=1; i<argc; i++)
    resolve(argv[i]);
  return flag_err ? 1 : 0;
}
