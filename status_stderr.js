// Start reading from stdin so we don't exit.
process.stdin.resume();

var DB = 'none';

var logMessage = function (message) {
	var d = new Date();
	var ds = d.getFullYear() + "-" + (d.getMonth() + 1) + "-" + d.getDate() + " " + d.getHours() + ":" + d.getMinutes() + ":" + d.getSeconds();
	process.stderr.write(ds + "  " + message + "  [" + DB + "]\n");
};

var logger = function (line) {
	var commentMatch = line.match(/^-- (.*)/);
	if (commentMatch) {
		var matches = line.match(/Current Database: `(.*)`/);
		if (matches) {
			DB = matches[1];
		}
		logMessage(commentMatch[1]);
	} else if (line.match(/^INSERT INTO/)) {
		logMessage("... insert");
	}
};

var writer = function (line) {
	if (DB != 'mysql') {
		process.stdout.write(line + "\n");
	}
};


var buff = '';
process.stdin.on('data', function (chunk) {
	buff += chunk.toString();
	buff = buff.split("\n");
	while (buff.length > 1) {
		var line = buff.shift();
		logger(line);
		writer(line);
	}
	buff = buff.shift();  // May return undefined, which is ok
});
process.stdin.on('end', function() {
	if (buff && buff.length) {
		logger(line);
		writer(line);
	}
});
