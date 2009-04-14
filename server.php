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
$log = fopen("log.txt", "w");
$date = date("D, d M Y H:i:s");
//fwrite($log, "[$date] Starting Web Server" . PHP_EOL);

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
} else {
    // Start the browser, point it to the landing page.
    //shell_exec("start http://localhost/patient.php");

    while (true) {
        // Check to see if the browser is still running. If not, shut down.
        // Windows only.
        $processes = shell_exec("wmic process");
        $pattern = '/(iexplore[.]exe)|(firefox[.]exe)/';
        if(!preg_match($pattern, $processes, $matches)) {
            break;
        }

        foreach($_POST as $key => $val) {
            unset($_POST[$key]);
        }

        foreach($_GET as $key => $val) {
            unset($_GET[$key]);
        }

        unset($conn);

        // Listen for a connection.
        while($conn = stream_socket_accept($socket, 2)) {
            unset($request);
            unset($inbound);
            unset($postInfo);

            $matches = array();
            $date = date("D, d M Y H:i:s");

            // Read the header.
            $end = 0;
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
            } while($end != 4);


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
                // Find out if we have to parse POST info.
                $head = explode(PHP_EOL, $request);
                $temp = $head[count($head)-3];

                $pattern = "/Content-Length: ([0-9]*)/";
                if(preg_match($pattern, $temp, $matches)) {
                    $postInfo = "";
                    do {
                        $inbound = stream_socket_recvfrom($conn, 1);
                        $postInfo .= $inbound;
                    } while(strlen($postInfo) < $matches[1]);

                    var_dump($postInfo);
                }
                $request .= $postInfo;

                // Split the request string into an array by line to make it easier to parse.
                $request = explode(PHP_EOL, $request);

                // Certain lines are well known. File is first, accept is 3rd, POST is last.
                $fileString = $request[0];
                $acceptString = $request[3];
                $postString = array_pop($request);

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
            }

            // Return a 404 if the file was not found.
            if(!file_exists($fullFileName)) {
                $headers = array();
                $headers[] = "HTTP/1.1 404 NOT FOUND";
                $headers[] = "Date: $date";
                $headers[] = "Content-Type: " . $mimeType[$extension];

                $content = "ERROR 404: File $fullFileName Not Found.";
            } else {
                if($extension == 'php') {
                    $content = getContent($fullFileName);
                } else {
                    $content = file_get_contents($fullFileName);
                }

                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                $headers[] = "Date: $date";
                $headers[] = "Content-Length: " . (strlen($content));
                $headers[] = "Content-Type: " . $mimeType[$extension];
            }

            stream_socket_sendto($conn, implode("\r\n", $headers) . "\r\n\r\n");
            stream_socket_sendto($conn, $content);
            fclose($conn);
        }
    }

  fclose($socket);
  fclose($log);
}

function getContent($fileName) {
    ob_start();
        require($fileName);
    $content = ob_get_clean();

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

    // Save the file somewhere ...
    $path = $webroot.'uploads/' . $vars['id'] . '/';

    if(file_exists($path)) {
        $file = fopen($path.$vars['Filename'], 'w');
    } else {
        mkdir($path);
        $file = fopen($path.$vars['Filename'], 'w');
    }
    fwrite($file, $vars['Filedata']);
    fclose($file);

}
?>