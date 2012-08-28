<?php
/**
 *  Author: Benjamin Maynor <BenjaminMaynor@gmail.com>
 *  April, 2009
 */

// don't timeout!
set_time_limit(0);

// Kills the error from date(); Set it to your time zone.
// See: http://us2.php.net/manual/en/timezones.php
date_default_timezone_set('America/Chicago');

// Setup the log file.
$date = date("D, d M Y H:i:s");

// Record errors from PHP
function errorLog($errno, $errstr, $errfile, $errline)
{
    if($errno != 2) {
        $errorLog = fopen("errorlog.txt", "a");
        $date = date("D, d M Y H:i:s");
        fwrite($errorLog, "[$date][$errline] $errno: $errstr ($errfile at line $errline)" . PHP_EOL . PHP_EOL);
        fclose($errorLog);
    }
}

set_error_handler("errorLog");

$mimeType = array(
    'docx'  => 'application/msword',
    'doc'   => 'application/msword',
    'pdf'   => 'application/pdf',
    'txt'   => 'text/plain',
    'mp3'   => 'audio/mp3',
    'php'   => 'text/html',
    'html'  => 'text/html',
    'htm'   => 'text/html',
    'phtml' => 'text/html',
    'css'   => 'text/css',
    'js'    => 'application/javascript',
    'jpg'   => 'image/jpg',
    'png'   => 'image/png',
    'gif'   => 'image/gif',
    'ico'   => 'image/icon',
    ''      => 'text/html'
);

// Your starting directory. Trailing '/' required.
$webroot = "scripts/";
// The default file to look for in your webroot.
$index = "index";
// And its extension
$indexExtension = "php";

$socket = stream_socket_server("tcp://127.0.0.1:81", $errno, $errstr);
if (!$socket) {
  echo "$errstr ($errno)<br />" . PHP_EOL;
} else {
    echo "Listening...\n";

    while (true) {
        $_POST = array();
        $_GET = array();

        unset($conn);

        // Listen for a connection.
        while($conn = stream_socket_accept($socket, 2)) {
            unset($request);
            unset($inbound);
            unset($postInfo);
            $request = "";

            $matches = array();
            $date = date("D, d M Y H:i:s");

            // Read the header.
            $end = 0;
            $count = 0;
            do {
                $inbound = stream_socket_recvfrom($conn, 1);
                $request .= $inbound;

                // End of header pattern is 13 10 13 10.
                if(ord($inbound) == 13 && ($end == 0 || $end == 2)) {
                    $end++;
                } else if(ord($inbound) == 10 && ($end == 1 || $end == 3)) {
                    $end++;
                } else {
                    $end = 0;
                }
                $count++;
            } while($end != 4 && $count < 25000);

            if($count == 25000) {
            	continue;
            }

            echo "Requested:\n\n";
            print_r($request);
            echo "\n\n";

            // Check to see if we are recieving a file from SWFUpload. If not, process as normal.
            $pattern = "/User-Agent: Shockwave Flash/";
            if(preg_match($pattern, $request)) {
                $head = explode(PHP_EOL, $request);
                $temp = $head[count($head)-5];

                $pattern = "/Content-Length: ([0-9]*)/";
                if(preg_match($pattern, $temp, $matches)) {
                    $postInfo = "";
                    do {
                        $inbound = stream_socket_recvfrom($conn, 1);
                        $postInfo .= $inbound;
                        $request .= $inbound;
                    } while(strlen($postInfo) < $matches[1]);
                }

                // Process from SWFUpload
                processUpload($request, $webroot);

                // Return OK
                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                stream_socket_sendto($conn, implode("\r\n", $headers) . "\r\n\r\n");
                fclose($conn);

            // Else, it's just a normal HTTP request. Serve it up.
            } else {
                // Parse the headers segment into individual headers
				preg_match_all(
					"/(?P<name>[^:]+): (?P<value>[^\r]+)(?:$|\r\n[^ \t]*)/U",
					$request,
					$parsed,
					PREG_SET_ORDER
				);

				$fileString = '';
				$contentLength = 0;
				$post = false;
				$postString = "";
                $getString = "";

                // Extract the parsed fields into something usable.
				foreach($parsed as $field) {
					if(strpos($field['name'], 'GET') !== FALSE)
					{
						$fileString = $field['name'];
					}
					if(strpos($field['name'], 'POST') !== FALSE)
					{
						$fileString = $field['name'];
						$post = true;
					}
					if(strpos($field['name'], 'Content-Length') !== FALSE)
					{
						$contentLength = $field['value'];
					}
				}

                // Find out if we have to parse POST info.
                if($contentLength > 0) {
                    do {
                        $inbound = stream_socket_recvfrom($conn, 1);
                        $postString .= $inbound;
                    } while(strlen($postString) < $contentLength);
                }

                if($postString) {
                	echo PHP_EOL ."Post Info($contentLength):" . PHP_EOL;
                	echo $postString . PHP_EOL . PHP_EOL;
                }

                // The entire file name being requested:
                $pattern = '/[\/]([^\s?]*)/';
                if(preg_match($pattern, $fileString, $matches)) {
                    // If we don't find a file match, use the index defined at the top of the file.
                    if(empty($matches[1])) {
                        $fileName = $index;
                        $extension = $indexExtension;
                    } else {
                        $file = $matches[1];
                        // Break it down into file . extension
                        $pattern = '/^([^\s]*)[.]([^\s?]*?)$/';
                        preg_match($pattern, $file, $matches);
                        $fileName = $matches[1];
                        $extension = $matches[2];
                    }
                }
                $fileName = urldecode($fileName);
                $fullFileName = $webroot . $fileName . "." . $extension;

                // Get the mime types accepted.
                $pattern = '/Accept: ([^;]*)/';
                if(preg_match($pattern, $acceptString, $matches)) {
                    $accept = $matches[1];
                }

                // Get the query string.
                $pattern = '/[?]([^\s]*)/';
                if(preg_match($pattern, $fileString, $matches)) {
                    $getString = $matches[1];
                    // Set the query string.
                    $_SERVER['QUERY_STRING'] = $getString;
                }
            }

            // Return a 404 if the file was not found.
            if(!file_exists($fullFileName)) {
                $headers = array();
                $headers[] = "HTTP/1.1 404 NOT FOUND";
                $headers[] = "Date: $date";
                $headers[] = "Content-Type: " . $mimeType[$extension];

                $content = "ERROR 404: File $fullFileName Not Found.";
            } else if($fullFileName == 'scripts/download.php') {
                $content = getContent($fullFileName, urldecode($getString), urldecode($postInfo));

                parse_str($getString, $get);

                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                $headers[] = "Date: $date";
                $headers[] = "Content-Length: " . (strlen($content));
                $headers[] = "Content-Type: " . $mimeType[$get['type']];

            } else {
                if($extension == 'php') {
                    $content = getContent($fullFileName, urldecode($getString), urldecode($postString));
                } else {
                    $content = file_get_contents($fullFileName);
                }

                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                $headers[] = "Date: $date";
                $headers[] = "Content-Length: " . (strlen($content));
                $headers[] = "Content-Type: " . $mimeType[$extension];
            }

            echo "Response:\n";
            print_r($headers);
            echo "\n\n";

            stream_socket_sendto($conn, implode("\r\n", $headers) . "\r\n\r\n");
            stream_socket_sendto($conn, $content);
            fclose($conn);
        }
    }

  fclose($socket);
}


/**
 * Get Content is what you would want to change to change the backing language. Right now, it executes PHP.
 */
function getContent($fileName, $getString, $postString) {

    $get  = escapeshellarg('get=1&'  . $getString);
    $post = escapeshellarg('post=1&' . $postString);

    $descriptorspec = array(
       0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
       1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
    );

    // We load php-cgi because we want our output interpreted for a browser.
    $process = proc_open("php-cgi loader.php $fileName $get $post", $descriptorspec, $pipes);

    if (is_resource($process)) {
        $content = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // It is important that you close any pipes before calling
        // proc_close in order to avoid a deadlock
        $return_value = proc_close($process);
    }

    // We want to strip out the pgp-cgi string (ie. X-Powered-By: PHP/5.4.4 Content-type: text/html);
    $pattern = "/X[-]Powered[-]By[:][^\n]*[\n][^\n]*[\n]/";
    $matches = array();
    $content = preg_replace($pattern, "", $content);

    return $content;
}
/**
  * Parses the content of the SWFUPload request and saves the file attached.
  * Directory structure used is webroot/uploads/procedureID
  *
  */
function processUpload($request, $webroot) {

    $matches = array();
    $pattern = '/boundary=(.*)/';
    preg_match($pattern, $request, $matches);
    $boundary = $matches[1];

    $pattern = '/Content-Disposition:.*filename=["](.*)["]/';
    preg_match($pattern, $request, $matches);
    $filename = $matches[1];

    $request = explode($boundary, $request);
    $content = $request[4];

    $vars = array();

    foreach($request as $line) {
        $matches = array();
        $pattern = '/Content-Disposition: form-data; name=["]([^"]*)["](.*)/sm';
        preg_match($pattern, $line, $matches);
        $var = $matches[1];

        if($var == "Filedata") {
            $val = str_replace('; filename="'.$filename.'"', '', $matches[2]);
            $val = str_replace('Content-Type: application/octet-stream', '', $val);
            $val = trim($val, "\x00..\x1F-");
        } else {
            $val = trim($matches[2], "\x00..\x1F-");
        }
        $vars[$var] = $val;
    }


    // If you want the server to treat downloads in the same manner PHP does,
    // use this code to store a temporary file. Then, fill out the $_FILES
    // array properly. As for cleaning up your temp file, best place would
    // probably be after the require for the uploadhandler.
    /*
    $path = $webroot.'uploads/';

    if(file_exists($path)) {
        $file = fopen($path.$vars['Filename'], 'w');
    } else {
        mkdir($path);
        $file = fopen($path.$vars['Filename'], 'w');
    }
    fwrite($file, $vars['Filedata']);
    fclose($file);
    */

    // Certain lines are well known. File is first, accept is 3rd, POST is last.
    $fileString = $request[0];

    // The entire file name being requested:
    $pattern = '/[\/]([^\s?]*)/';
    if(preg_match($pattern, $fileString, $matches)) {
        // If we don't find a file match, use the index defined at the top of the file.
        $file = $matches[1];
        // Break it down into file . extension
        $pattern = '/^([^\s]*)[.]([^\s?]*?)$/';
        preg_match($pattern, $file, $matches);
        $fileName = $matches[1];
        $extension = $matches[2];
    }

    $fileName = urldecode($fileName);
    $uploadHandler = $webroot . $fileName . "." . $extension;

    $_FILES['name'] = $vars['Filename'];
    $_FILES['content'] = $vars['Filedata'];
    $_FILES['procedure_id'] = $vars['id'];

    require($uploadHandler);
}
?>

