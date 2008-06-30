<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
* Short description for file
*
* Long description for file (if any)...
*
* PHP versions 4 and 5
*
* LICENSE: This source file is subject to version 3.0 of the PHP license
* that is available through the world-wide-web at the following URI:
* http://www.php.net/license/3_0.txt.  If you did not receive a copy of
* the PHP License and are unable to obtain it through the web, please
* send a note to license@php.net so we can mail you a copy immediately.
*
* @category   CategoryName
* @package    Freerange
* @author     EJ <ej@racklabs.com>
* @copyright  2008 Rackspace US, Inc.
* @license    http://www.php.net/license/3_0.txt  PHP License 3.0
* @version    CVS: $Id:$
* @link       http://pear.php.net/package/Freerange
* @see        NetOther, Net_Sample::Net_Sample()
* @since      File available since Release 1.2.0
* @deprecated File deprecated in Release 2.0.0
*/

define("DEFAULT_CLOUDFS_API_VERSION", 1);
define("STORAGE_URL", "x-storage-url:");
define("STORAGE_TOKEN", "x-storage-token:");

/**
 * The base authentication class from which all others inherit.
 */
class CLOUDFS_Authentication
{
    var $account;
    var $url;
    var $uri;
    var $headers;
    var $conn_uri;
    var $port;
    var $dbug;

    var $storage_token;
    var $storage_url;

    function CLOUDFS_Authentication($account, $username, $password, $url)
    {
        if (!$account || !$username || !$password || !$url) { return False; }

        $this->dbug = False;
        $this->storage_token = NULL;
        $this->storage_url = NULL;
        $this->error_status_code = NULL;
        $this->error_status_reason = NULL;
        $this->account = $account;
        $this->url = $url;
        $this->headers = array();
        $this->headers[] = "X-Storage-User: $username";
        $this->headers[] = "X-Storage-Pass: $password";
        $this->curl_ch = $this->_init_curl();
    }

    function _get_uri($version)
    {
        $auth_uri = array();
        if (strlen($this->uri)) {
            $auth_uri[] = $this->uri;
        }
        // will form "/v1/AccountName/auth"
        $auth_uri[] = urlencode(sprintf("v%d", $version));
        $auth_uri[] = urlencode($this->account);
        $auth_uri[] = "auth";

        return "/" . implode("/", $auth_uri);
    }

    function _response_header_callback($ch, $header)
    {
        #if (strncasecmp($header, STORAGE_URL, strlen(STORAGE_URL) === 0)) {
        if (stripos($header, STORAGE_URL) === 0) {
            $this->storage_url = trim(substr($header, strlen(STORAGE_URL)+1));
        }
        if (stripos($header, STORAGE_TOKEN) === 0) {
            $this->storage_token = trim(substr($header, strlen(STORAGE_TOKEN)+1));
        }
        if (strpos($header, "HTTP/1.1") === 0) {
            preg_match("/^HTTP\/1\.1 (\d+) (.*)/", $header, $matches);
            $this->response_code = $matches[1];
            $this->response_reason = $matches[2];
        }
        return strlen($header);
    }

    function _init_curl($version=DEFAULT_CLOUDFS_API_VERSION)
    {
        $this->curl_ch = curl_init();
        if ($this->dbug) {
            curl_setopt($this->curl_ch, CURLOPT_VERBOSE, 1);
        }
        curl_setopt($this->curl_ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->curl_ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($this->curl_ch, CURLOPT_HEADER, 0); // output headers?
        curl_setopt($this->curl_ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($this->curl_ch, CURLOPT_HEADERFUNCTION,
                array($this, '_response_header_callback'));
        curl_setopt($this->curl_ch, CURLOPT_NOBODY, 1);

        curl_setopt($this->curl_ch, CURLOPT_URL,
                $this->url.$this->_get_uri($version));
    }

    function _curl_close()
    {
        curl_close($this->curl_ch);
    }

    /**
     * Initiates authentication with the remote service and returns a 
     * two-tuple containing the storage system URL and session token.
     * Accepts a single (optional) argument for the storage system
     * API version.
     */
    function authenticate($version=DEFAULT_CLOUDFS_API_VERSION)
    {
        $this->_init_curl();

        curl_exec($this->curl_ch);

        if ($this->response_code != 204) {
            $this->error_status_code = $this->response_code;
            $this->error_status_reason =  $this->response_reason;
            $this->_curl_close();
            print "EXIT1\n";
            return False;
        }

        if (!$this->storage_url and !$this->storage_token) {
            $this->error_status_code = 0;
            $this->error_status_reason = "Invalid response from auth service.";
            $this->_curl_close();
            print "EXIT2\n";
            return False;
        }

        $parts = explode("/",$this->storage_url);
        $this->storage_account = $parts[count($parts)-1];
        $this->_curl_close();
        return True;
    }
}
