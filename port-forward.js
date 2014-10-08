#!/usr/bin/env node

var addrRegex, from, net, to;

net = require('net');

// parse "80" and "localhost:80" or even "42mEANINg-life.com:80"
addrRegex = /^(([a-zA-Z\-\.0-9]+):)?(\d+)$/;

from = addrRegex.exec(process.argv[2]);
to = addrRegex.exec(process.argv[3]);

if (!from || !to) {
	console.log('Usage: <from> <to>');
} else {
	net.createServer(function (incoming) {
		var outgoing;

		outgoing = net.createConnection({
			host: to[2],
			port: to[3]
		});
		incoming.pipe(outgoing);
		outgoing.pipe(incoming);
	}).listen(from[3], from[2]);
}
