#!/usr/bin/php
<?php

$files = $GLOBALS['argv'];
array_shift($files);

if (! $files) {
	$files = array(
		'php://stdin',
	);
}

foreach ($files as $file) {
	minify($file);
}

function minify($filename) {
	$script = file_get_contents($filename);
	$url = 'http://closure-compiler.appspot.com/compile';
	$content = http_build_query(array(
			'js_code' => $script,
			'output_info' => 'compiled_code',  // if 'errors', return errors about JS code
			'output_format' => 'text',
			//'compilation_level' => 'SIMPLE_OPTIMIZATIONS' // Safer and works well
			'compilation_level' => 'ADVANCED_OPTIMIZATIONS',  // More aggressive, can cause errors
		));
	$contents = file_get_contents($url, false, stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => $content,
					'max_redirects' => 0,
					'timeout' => 30,
				))));

	echo $contents;
}
