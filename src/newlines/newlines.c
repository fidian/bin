#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#define MODE_CONVERT 0
#define MODE_COUNT 1
#define MODE_STRIP 2

#define DEST_UNIX 0
#define DEST_MAC 1
#define DEST_DOS 2

#define COUNT_UNIX 0
#define COUNT_MAC 1
#define COUNT_DOS 2

#define CHUNK_SIZE 8192

void showHelp(void) {
	fprintf(stderr, "newlines:  Cool newline handling program\n");
	fprintf(stderr, "  newlines [options] [files]\n");
	fprintf(stderr, "By default, the program will convert newlines from stdin to stdout\n");
	fprintf(stderr, "  -c        Show newline counts (stops newline conversion)\n");
	fprintf(stderr, "  -s        Strip specified type of newlines (does not convert/count)\n");
	fprintf(stderr, "  -d        Convert to / test for / strip DOS-style newlines\n");
	fprintf(stderr, "  -m        Convert to / test for / strip Mac-style newlines\n");
	fprintf(stderr, "  -u        Convert to / test for / strip Unix-style newlines (default)\n");
	fprintf(stderr, "  -i        Do an \"in place\" edit of the file\n");
	fprintf(stderr, "  -h        Print this help message\n");
	fprintf(stderr, "  -q        Quiet - do not print anything\n");
	fprintf(stderr, "\n");
	fprintf(stderr, "When using -i, you must also specify at least one filename.\n");
	fprintf(stderr, "When using -c, the return code will be zero if all of the newlines\n");
	fprintf(stderr, "match your -d, -m, or -u parameter.  For multiple files, the return\n");
	fprintf(stderr, "code only matters for the last file on the command line.\n");
	fprintf(stderr, "unix = 0x0A (LF)\tmac = 0x0D (CR)\tdos = 0x0D 0x0A (CR LF)\n");
}

void countResults(int type, unsigned int count) {
	if (count == 0) {
		return;
	}
	switch (type) {
		case COUNT_UNIX:
			printf("unix: %u\n", count);
			break;
		case COUNT_MAC:
			printf("mac: %u\n", count);
			break;
		case COUNT_DOS:
			printf("dos: %u\n", count);
			break;
		default:
			printf("????: %u\n", count);
	}
}

int doCount(FILE *in, int dest, int quiet) {
	unsigned int unx = 0, mac = 0, dos = 0;
	int c = 0, lastC = 0;

	while (! feof(in)) {
		lastC = c;
		c = fgetc(in);
		if (c == 0x0D) {
			mac ++;
		} else if (c == 0x0A) {
			if (lastC == 0x0D) {
				mac --;
				dos ++;
			} else {
				unx ++;
			}
		}
	}

	if (! quiet) {
		if (unx >= mac) {
			if (unx >= dos) {
				countResults(COUNT_UNIX, unx);
				if (dos >= mac) {
					countResults(COUNT_DOS, dos);
					countResults(COUNT_MAC, mac);
				} else {
					countResults(COUNT_MAC, mac);
					countResults(COUNT_DOS, dos);
				}
			} else {
				countResults(COUNT_DOS, dos);
				countResults(COUNT_UNIX, unx);
				countResults(COUNT_MAC, mac);
			}
		} else {
			countResults(COUNT_MAC, mac);
			if (unx >= dos) {
				countResults(COUNT_UNIX, unx);
				countResults(COUNT_DOS, dos);
			} else {
				countResults(COUNT_DOS, dos);
				countResults(COUNT_UNIX, unx);
			}
		}
		if (! unx && ! dos && ! mac) {
			printf("No newlines detected.\n");
		}
	}

	if (dest == DEST_UNIX && ! dos && ! mac) {
		return 0;
	}
	if (dest == DEST_DOS && ! unx && ! mac) {
		return 0;
	}
	if (dest == DEST_MAC && ! unx && ! dos) {
		return 0;
	}

	return 1;
}

size_t doConvert(FILE *in, FILE *out, int dest) {
	int c = 0, lastC = 0;
	static char *newlines[] = {"\n", "\r", "\r\n"};
	size_t length = 0;

	while (! feof(in)) {
		lastC = c;
		c = fgetc(in);
		if (c != EOF) {
			if (c == 0x0D) {
				fputs(newlines[dest], out);
				length ++;
				if (dest == DEST_DOS) {
					length ++;
				}
			} else if (c == 0x0A) {
				if (lastC != 0x0D) {
					fputs(newlines[dest], out);
					length ++;
					if (dest == DEST_DOS) {
						length ++;
					}
				}
			} else {
				fputc(c, out);
				length ++;
			}
		}
	}

	return length;
}

size_t doStrip(FILE *in, FILE *out, int dest) {
	int c = 0, nextC = 0;
	static char *newlines[] = {"\n", "\r", "\r\n"};
	size_t length = 0;

	if (dest != DEST_DOS) {
		while (! feof(in)) {
			c = fgetc(in);
			if (c != EOF && c != newlines[dest][0]) {
				fputc(c, out);
				length ++;
			}
		}
	} else {
		// DOS's two-character newlines
		while (! feof(in)) {
			c = fgetc(in);
			while (c == newlines[dest][0]) {
				// Need to confirm second byte matches and base action on both characters
				nextC = fgetc(in);
				if (nextC != newlines[dest][1]) {
					fputc(c, out);
					length ++;
					c = nextC;
				} else {
					c = fgetc(in);
				}
			}
			if (c != EOF) {
				fputc(c, out);
				length ++;
			}
		}
	}

	return length;
}

int main(int argc, char **argv) {
	FILE *in = stdin, *out = stdout;
	int i, retcode = 0, mode = MODE_CONVERT, dest = DEST_UNIX, inplace = 0;
	int filesProcessed = 0, quiet = 0;
	char buffer[CHUNK_SIZE];
	size_t length = 0, left, chunk, confirm;

	for (i = 1; i < argc; i ++) {
		if (strcmp(argv[i], "-c") == 0) {
			mode = MODE_COUNT;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-s") == 0) {
			mode = MODE_STRIP;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-d") == 0) {
			dest = DEST_DOS;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-h") == 0 || strcmp(argv[i], "--help") == 0) {
			showHelp();
			return 1;
		} else if (strcmp(argv[i], "-i") == 0) {
			inplace = 1;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-m") == 0) {
			dest = DEST_MAC;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-q") == 0) {
			quiet = 1;
			argv[i][0] = '\0';
		} else if (strcmp(argv[i], "-u") == 0) {
			dest = DEST_UNIX;
			argv[i][0] = '\0';
		}
	}

	for (i = 1; i < argc; i ++) {
		if (argv[i][0] != '\0') {
			in = fopen(argv[i], "rb+");
			if (! in) {
				fprintf(stderr, "Unable to open input:  %s\n", argv[i]);
				exit(5);
			}
			if (mode == MODE_COUNT) {
				retcode = doCount(in, dest, quiet);
			} else if (inplace) {
				out = tmpfile();
				if (mode == MODE_STRIP) {
					length = doStrip(in, out, dest);
				} else {
					length = doConvert(in, out, dest);
				}
				rewind(in);
				rewind(out);
				left = length;
				while (left) {
					chunk = CHUNK_SIZE;
					if (left < chunk) {
						chunk = left;
					}
					confirm = fread(buffer, 1, chunk, out);
					if (confirm != chunk) {
						fprintf(stderr, "Error copying contents from temp file to original (read).\n");
						exit(6);
					}
					confirm = fwrite(buffer, 1, chunk, in);
					if (confirm != chunk) {
						fprintf(stderr, "Error copying contents from temp file to original (write).\n");
						exit(6);
					}
					left -= chunk;
				}
				fclose(out);
				fclose(in);
				if (truncate(argv[i], length)) {
					fprintf(stderr, "Error truncating file\n");
					exit(6);
				}
			} else {
				if (mode == MODE_STRIP) {
					doStrip(in, stdout, dest);
				} else {
					doConvert(in, stdout, dest);
				}
			}
			filesProcessed ++;
		}
	}

	if (filesProcessed == 0) {
		if (inplace) {
			fprintf(stderr, "When using -i, you must also specify at least one file.");
			exit(3);
		}
		if (mode == MODE_COUNT) {
			retcode = doCount(in, dest, quiet);
		} else {
			doConvert(stdin, stdout, dest);
		}
	}

	return retcode;
}
