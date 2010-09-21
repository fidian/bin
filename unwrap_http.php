#!/usr/bin/php
<?PHP

require_once(dirname(__FILE__) . '/../bigidea/app/classes/dump.class.php');

$files = $GLOBALS['argv'];
array_shift($files);

if (! $files) {
	$files = array(
		'php://stdin',
	);
}

foreach ($files as $file) {
	unwrap($file);
}


function unwrap($filename) {
	$data = file_get_contents($filename);

	// Remove lines until the header is done
	$inHeader = true;
	while ($inHeader) {
		$line = readOneLine($data);
		if ($line == '') {
			$inHeader = false;
		}
		if (empty($data)) {
			return;
		}
	}

	// Read a length and write
	while (strlen($data)) {
		$bytesInHex = readOneLine($data);
		$bytes = hexdec($bytesInHex);
		echo substr($data, 0, $bytes);
		$data = substr($data, $bytes + 1);
	}

	//Dump::out($data);
}

function readOneLine(&$data) {
	if (strlen($data) == 0) {
		return '';
	}

	$x = explode("\r\n", $data, 2);
	if (isset($x[1])) {
		$data = $x[1];
	} else {
		$data = '';
	}
	return $x[0];
}

// blah
// ^M
// fe8^M
// content content content^M
// 0^M
// ^M
