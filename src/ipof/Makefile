CC=gcc
CCOPTS=-Os -Wall
STRIP=strip
STRIPOPTS=--strip-unneeded

all: ipof

install: ipof
	cp ipof ../..

ipof: ipof.c
	$(CC) $(CCOPTS) ipof.c -o ipof
	$(STRIP) $(STRIPOPTS) ipof

clean:
	rm -f ipof
