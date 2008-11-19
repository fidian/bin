CC=gcc
CCOPTS=-Os -Wall
STRIP=strip
STRIPOPTS=--strip-unneeded

all: newlines

install: newlines
	cp newlines ../..

newlines: newlines.c
	$(CC) $(CCOPTS) newlines.c -o newlines
	$(STRIP) $(STRIPOPTS) newlines

test: newlines
	echo -n "\r\r\r" | ./newlines -c | md5sum | grep "1f8e4c1412c297c31c7a7f39219d18af" > /dev/null
	echo -n "\n\n\n" | ./newlines -c | md5sum | grep "c2815cf5066c29cb64d4c170bd3af474" > /dev/null
	echo -n "\r\n\r\n\r\n" | ./newlines -c | md5sum | grep "558fcce8905563d3ef8fca85c208ba84" > /dev/null
	echo -n "\r \r\n \n \n\r" | ./newlines -c | md5sum | grep "11af10c6a5ccc7bb2faf225db854c6ee" > /dev/null
	echo -n "\r\r\r" | ./newlines -d | md5sum | grep "ed19ca99581136d44b35bbb2240a6bf6" > /dev/null
	echo -n "\n\n\n" | ./newlines -m | md5sum | grep "8b5d99363270804f6cd9b4cf54c2d7f3" > /dev/null
	echo -n "\r\n\r\n\r\n" | ./newlines | md5sum | grep "2228e977ebea8966e27929f43e39cb67" > /dev/null
	echo -n "\r \r\n \n \n\r" | ./newlines -d | md5sum | grep "b1c9e93d330723292e8b97a411de6458" > /dev/null
	echo "\n\nTests passed.\n"

clean:
	rm -f newlines
