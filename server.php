<?php
// don't timeout!
set_time_limit(0);

// Kills the error from date(); Set it to your time zone.
// See: http://us2.php.net/manual/en/timezones.php
date_default_timezone_set('America/Chicago');

// Setup the log file.
$log = fopen("log.txt", "w");
$date = date("D, d M Y H:i:s");
//fwrite($log, "[$date] Starting Web Server" . PHP_EOL);

// Record errors from PHP
function errorLog($errno, $errstr, $errfile, $errline)
{
    $errorLog = fopen("errorlog.txt", "a");
    $date = date("D, d M Y H:i:s");
    fwrite($errorLog, "[$date][$errline] $errno: $errstr ($errfile at line $errline)" . PHP_EOL . PHP_EOL);
    fclose($errorLog);
}

set_error_handler("errorLog");

$mimeType = array(
    'php'   => 'text/html',
    'html'  => 'text/html',
    'htm'  => 'text/html',
    'phtml' => 'text/html',
    'css'   => 'text/css',
    'js'    => 'application/javascript',
    'jpg'   => 'image/jpg',
    'png'   => 'image/png',
    'gif'   => 'image/gif',
    ''      => 'text/html'
);

// Your starting directory. Trailing '/' required.
$webroot = "scripts/";
// The default file to look for in your webroot.
$index = "index";
// And its extension
$indexExtension = "php";

$socket = stream_socket_server("tcp://127.0.0.1:80", $errno, $errstr);
if (!$socket) {
  echo "$errstr ($errno)<br />" . PHP_EOL;
  //fwrite($log, "[$date] Error: Starting Web Server: $errstr ($errno) " . PHP_EOL);
} else {
    //fwrite($log, "[$date] Listening ... " . PHP_EOL);

    // Start the browser, point it to the landing page.
    shell_exec("start http://localhost/patient.php");

    var_dump(array_diff($before, $after));

    while (true) {
        // Check to see if the browser is still running. If not, shut down.
        // Windows only.
        $processes = shell_exec("wmic process");
        $pattern = '/(iexplore[.]exe)|(firefox[.]exe)/';
        if(!preg_match($pattern, $processes, $matches)) {
            break;
        }

        // Listen for a connection.
        while($conn = stream_socket_accept($socket, 1)) {
            $date = date("D, d M Y H:i:s");

            $request = fread($conn, 10240);

            ////fwrite($log, "[$date] Request Recieved:" . PHP_EOL . "$request" . PHP_EOL);

            // Split the request string into an array by line to make it easier to parse.
            $request = explode(PHP_EOL, $request);

            // Certain lines are well known. File is first, accept is 3rd, POST is last.
            $fileString = $request[0];
            $acceptString = $request[3];
            $postString = array_pop($request);

            $matches = array();

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
            $fullFileName = $webroot . $fileName . "." . $extension;

            // Get the mime types accepted.
            $pattern = '/Accept: ([^;]*)/';
            if(preg_match($pattern, $acceptString, $matches)) {
                $accept = $matches[1];
            }

            // Get the query string.
            $pattern = '/[?]([^\s]*)/';
            if(preg_match($pattern, $fileString, $matches)) {
                $queryString = $matches[1];

                // Set the query string.
                $_SERVER['QUERY_STRING'] = $queryString;

                // Store the $_GET variables from the query string.
                $queries = array();
                parse_str($queryString, $queries);
                foreach($queries as $var => $val) {
                    $_GET[$var] = $val;
                }
            }

            // Process POST data:
            $pattern = '/([^\s]*[=][^\s&]*)*/';
            if(preg_match($pattern, $postString, $matches)) {
                $post = array();
                if(isset($matches[1])) {
                    parse_str($matches[1], $post);
                    foreach($post as $var => $val) {
                        $_POST[$var] = $val;
                    }
                }
            }

            // Debugging Log
            //fwrite($log, "[$date] File Name: $fileName" . PHP_EOL);
            //fwrite($log, "[$date] Extension: $extension" . PHP_EOL);
            //fwrite($log, "[$date] Full File Name: $fullFileName" . PHP_EOL);
            //fwrite($log, "[$date] Accept: $accept" . PHP_EOL);

            // Return a 404 if the file was not found.
            if(!file_exists($fullFileName)) {
                $headers = array();
                $headers[] = "HTTP/1.1 404 NOT FOUND";
                $headers[] = "Date: $date";
                $headers[] = "Content-Type: " . $mimeType[$extension];

                $content = "ERROR 404: File $fullFileName Not Found.";
            } else {
                if($extension == 'php') {
                    ob_start();
                        require($fullFileName);
                    $content = ob_get_clean();
                } else {
                    $content = file_get_contents($fullFileName);
                }

                //fwrite($log, "[$date] File Size: " . (strlen($content)) . PHP_EOL . PHP_EOL);

                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                $headers[] = "Date: $date";
                $headers[] = "Content-Length: " . (strlen($content));
                $headers[] = "Content-Type: " . $mimeType[$extension];
            }

            fwrite($conn, implode("\r\n", $headers) . "\r\n\r\n");
            fwrite($conn, $content);
            fclose($conn);
        }
    }
  fclose($socket);
  //fwrite($log, "[$date] Closing Web Server" . PHP_EOL . PHP_EOL);
  fclose($log);
}
?>