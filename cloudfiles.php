<?php
/**
 * This is the PHP Cloud Files API.
 *
 * <code>
 *   # Authenticate to Cloud Files
 *   #
 *   $auth = new CF_Authentication($username, $api_key);
 *   $auth->authenticate();
 *
 *   # Establish a connection to the storage system
 *   #
 *   $conn = new CF_Connection($auth);
 *
 *   # Create a remote Container and storage Object
 *   #
 *   $images = $conn->create_container("photos");
 *   $bday = $images->create_object("first_birthday.jpg");
 *
 *   # Upload content from a local file by streaming it
 *   #
 *   $fname = "/home/user/photos/birthdays/birthday1.jpg";  # filename to upload
 *   $size = filesize($fname);
 *   $fp = open($fname, "r");
 *   $bday->write($fp, $size);
 *
 *   # Or... use a convenience function instead
 *   #
 *   $bday->load_from_filename("/home/user/photos/birthdays/birthday1.jpg");
 *
 *   # Now, publish the "photos" container to serve the images by CDN.
 *   # Use the "$uri" value to put in your web pages or send the link in an
 *   # email message, etc.
 *   #
 *   $uri = $images->make_public();
 *
 *   # Or... print out the Object's public URI
 *   #
 *   print $bday->public_uri();
 * </code>
 *
 * See the included tests directory for additional sample code.
 *
 * Requres PHP 5.x (for Exceptions and OO syntax) and PHP's cURL module.
 *
 * It uses the supporting "cloudfiles_http.php" module for HTTP(s) support and
 * allows for connection re-use and streaming of content into/out of Cloud Files
 * via PHP's cURL module.
 *
 * See COPYING for license information.
 *
 * @author Eric "EJ" Johnson <ej@racklabs.com>
 * @copyright Copyright (c) 2008, Rackspace US, Inc.
 * @package php-cloudfiles
 */

/**
 */
require_once("cloudfiles_exceptions.php");
require("cloudfiles_http.php");
define("DEFAULT_CF_API_VERSION", 1);
define("MAX_CONTAINER_NAME_LEN", 64);
define("MAX_OBJECT_NAME_LEN", 128);

/**
 * Class for handling Cloud Files Authentication, call it's {@link authenticate()}
 * method to obtain authorized service urls and an authentication token.
 *
 * Example:
 * <code>
 * # Create the authentication instance
 * #
 * $auth = new CF_Authentication("username", "api_key");
 *
 * # Perform authentication request
 * #
 * $auth->authenticate();
 * </code>
 *
 * @package php-cloudfiles
 */
class CF_Authentication
{
    public $dbug;
    public $username;
    public $api_key;
    public $auth_host;
    public $account;

    /**
     * Instance variables that are set after successful authentication
     */
    public $storage_url;
    public $cdnm_url;
    public $auth_token;

    /**
     * Class constructor (PHP 5 syntax)
     *
     * @param string $username Mosso username
     * @param string $api_key Mosso API Access Key
     * @param string $account <b>Deprecated</b> <i>Account name</i>
     * @param string $auth_host <b>Deprecated</b> <i>Authentication service URI</i>
     */
    function __construct($username, $api_key, $account=NULL, $auth_host=NULL)
    {
        if (!$username || !$api_key) {
            throw new SyntaxException("Missing required constructor arguments.");
        }

        $this->dbug = False;
        $this->username = $username;
        $this->api_key = $api_key;
        $this->account_name = $account;
        $this->auth_host = $auth_host;

        $this->storage_url = NULL;
        $this->cdnm_url = NULL;
        $this->auth_token = NULL;

        $this->cfs_http = new CF_Http(DEFAULT_CF_API_VERSION);
    }

    /**
     * Attempt to validate Username/API Access Key
     *
     * Attempts to validate credentials with the authentication service.  It
     * either returns <kbd>True</kbd> or throws an Exception.  Accepts a single
     * (optional) argument for the storage system API version.
     *
     * Example:
     * <code>
     * # Create the authentication instance
     * #
     * $auth = new CF_Authentication("username", "api_key");
     *
     * # Perform authentication request
     * #
     * $auth->authenticate();
     * </code>
     *
     * @param string $version API version for Auth service (optional)
     * @return boolean <kbd>True</kbd> if successfully authenticated
     * @throws AuthenticationException invalid credentials
     * @throws InvalidResponseException invalid response
     */
    function authenticate($version=DEFAULT_CF_API_VERSION)
    {
        list($status,$reason,$surl,$curl,$atoken) = 
                $this->cfs_http->authenticate($this->username, $this->api_key,
                $this->account_name, $this->auth_host);

        if ($status == 401) {
            throw new AuthenticationException("Invalid username or access key.");
        }
        if ($status != 204) {
            throw new InvalidResponseException(
                "Unexpected response (".$status."): ".$reason);
        }

        if (!($surl || $curl) || !$atoken) {
            throw new InvalidResponseException(
                "Expected headers missing from auth service.");
        }
        $this->storage_url = $surl;
        $this->cdnm_url = $curl;
        $this->auth_token = $atoken;
        return True;
    }

    /**
     * Toggle debugging - set cURL verbose flag
     */
    function setDebug($bool)
    {
        $this->dbug = $bool;
        $this->cfs_http->setDebug($bool);
    }
}

/**
 * Class for establishing connections to the Cloud Files storage system.
 * Connection instances are used to communicate with the storage system at
 * the account level; listing and deleting Containers and returning Container
 * instances.
 *
 * Example:
 * <code>
 * # Create the authentication instance
 * #
 * $auth = new CF_Authentication("username", "api_key");
 *
 * # Perform authentication request
 * #
 * $auth->authenticate();
 *
 * # Create a connection to the storage/cdn system(s) and pass in the
 * # validated CF_Authentication instance.
 * #
 * $conn = new CF_Connection($auth);
 * </code>
 *
 * @package php-cloudfiles
 */
class CF_Connection
{
    public $dbug;
    public $cfs_http;

    public $storage_url;
    public $cdnm_url;
    public $auth_token;

    /**
     * Pass in a previously authenticated CF_Authentication instance.
     *
     * Example:
     * <code>
     * # Create the authentication instance
     * #
     * $auth = new CF_Authentication("username", "api_key");
     *
     * # Perform authentication request
     * #
     * $auth->authenticate();
     *
     * # Create a connection to the storage/cdn system(s) and pass in the
     * # validated CF_Authentication instance.
     * #
     * $conn = new CF_Connection($auth);
     * </code>
     *
     * @param obj $cfs_auth previously authenticated CF_Authentication instance
     * @throws AuthenticationException not authenticated
     */
    function __construct($cfs_auth)
    {
        $this->cfs_http = new CF_Http(DEFAULT_CF_API_VERSION);
        $this->storage_url = $cfs_auth->storage_url;
        $this->cdnm_url = $cfs_auth->cdnm_url;
        $this->auth_token = $cfs_auth->auth_token;
        if (!($this->storage_url || $this->cdnm_url) || !$this->auth_token) {
            $e = "Need to pass in a previously authenticated ";
            $e .= "CF_Authentication instance.";
            throw new AuthenticationException($e);
        }
        $this->cfs_http->setStorageUrl($this->storage_url);
        $this->cfs_http->setCDNMUrl($this->cdnm_url);
        $this->cfs_http->setAuthToken($this->auth_token);
        $this->dbug = False;
    }

    /**
     * Toggle debugging of instance and back-end HTTP module
     *
     * @param boolean $bool enable/disable cURL debugging
     */
    function setDebug($bool)
    {
        $this->dbug = (boolean) $bool;
        $this->cfs_http->setDebug($this->dbug);
    }

    /**
     * Cloud Files account information
     *
     * Return an array of two integers (or possibly floats if the value
     * overflows PHP's 32-bit integer); number of containers on the account
     * and total bytes used for the account.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * list($quantity, $bytes) = $conn->get_info();
     * print "Number of containers: " . $quantity . "\n";
     * print "Bytes stored in container: " . $bytes . "\n";
     * </code>
     *
     * @return array (number of containers, total bytes stored)
     * @throws InvalidResponseException unexpected response
     */
    function get_info()
    {
        list($status, $reason, $container_count, $total_bytes) =
                $this->cfs_http->head_account();
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        return array($container_count, $total_bytes);
    }

    /**
     * Create a Container
     *
     * Given a Container name, return a Container instance, creating a new
     * remote Container if it does not exit.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $images = $conn->create_container("my photos");
     * </code>
     *
     * @param string $container_name container name
     * @return CF_Container
     * @throws SyntaxException invalid name
     * @throws InvalidResponseException unexpected response
     */
    function create_container($container_name=NULL)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        if (strpos($container_name, "/") !== False) {
            $r = "Container name '".$container_name;
            $r .= "' cannot contain a '/' character.";
            throw new SyntaxException($r);
        }
        if (mb_strlen($container_name, "UTF-8") > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException(sprintf(
                "Container name exeeds %d characters.",
                MAX_CONTAINER_NAME_LEN));
        }

        $return_code = $this->cfs_http->create_container($container_name);
        if (!$return_code) {
            throw new InvalidResponseException("Invalid response ("
                . $return_code. "): " . $this->cfs_http->get_error());
        }
        if ($return_code != 201 && $return_code != 202) {
            throw new InvalidResponseException(
                "Invalid response (".$return_code."): "
                    . $this->cfs_http->get_error());
        }
        return new CF_Container($this->cfs_http, $container_name);
    }

    /**
     * Delete a Container
     *
     * Given either a Container instance or name, remove the remote Container.
     * The Container must be empty prior to removing it.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $conn->delete_container("my photos");
     * </code>
     *
     * @param string|obj $container container name or instance
     * @return boolean <kbd>True</kbd> if successfully deleted
     * @throws SyntaxException missing proper argument
     * @throws InvalidResponseException invalid response
     * @throws NonEmptyContainerException container not empty
     * @throws NoSuchContainerException remote container does not exist
     */
    function delete_container($container=NULL)
    {
        if (is_object($container)) {
            if (get_class($container) == "CF_Container") {
                $container_name = $container->name;
            }
        }
        if (is_string($container)) {
            $container_name = $container;
        }
        if (!$container_name) {
            throw new SyntaxException("Must specify container object or name.");
        }

        $return_code = $this->cfs_http->delete_container($container_name);

        if (!$return_code) {
            throw new InvalidResponseException("Failed to obtain http response");
        }
        if ($return_code == 409) {
            throw new NonEmptyContainerException(
                "Container must be empty prior to removing it.");
        }
        if ($return_code == 404) {
            throw new NoSuchContainerException(
                "Specified container did not exist to delete.");
        }
        if ($return_code != 204) {
            throw new InvalidResponseException(
                "Invalid response (".$return_code."): "
                . $this->cfs_http->get_error());
        }
        return True;
    }

    /**
     * Return a Container instance
     *
     * For the given name, return a Container instance if the remote Container
     * exists, otherwise throw a Not Found exception.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $images = $conn->get_container("my photos");
     * print "Number of Objects: " . $images->size . "\n";
     * print "Bytes stored in container: " . $images->bytes . "\n";
     * </code>
     *
     * @param string $container_name name of the remote Container
     * @return container CF_Container instance
     * @throws NoSuchContainerException thrown if no remote Container
     * @throws InvalidResponseException unexpected response
     */
    function get_container($container_name=NULL)
    {
        list($status, $reason, $size, $bytes) =
                $this->cfs_http->head_container($container_name);
        if ($status == 404) {
            throw new NoSuchContainerException("Container not found.");
        }
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return new CF_Container($this->cfs_http,$container_name,$size,$bytes);
    }

    /**
     * Return list of remote Containers
     *
     * Return an array of strings containing the names of all remote Containers.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $container_list = $conn->list_containers();
     * print_r($container_list);
     * Array
     * (
     *     [0] => "my photos",
     *     [1] => "my docs"
     * )
     * </code>
     *
     * @return array list of remote Containers
     * @throws InvalidResponseException unexpected response
     */
    function list_containers()
    {
        list($status, $reason, $containers) = $this->cfs_http->list_containers();
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        return $containers;
    }

    /**
     * Return list of Containers that have been published to the CDN.
     *
     * Return an array of strings containing the names of published Containers.
     * Note that this function returns the list of any Container that has
     * ever been CDN-enabled regardless of it's existence in the storage
     * system.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_containers = $conn->list_public_containers();
     * print_r($public_containers);
     * Array
     * (
     *     [0] => "images",
     *     [1] => "css",
     *     [2] => "javascript"
     * )
     * </code>
     *
     * @return array list of published Container names
     * @throws InvalidResponseException unexpected response
     */
    function list_public_containers()
    {
        list($status, $reason, $containers) =
                $this->cfs_http->list_cdn_containers();
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        return $containers;
    }

    /**
     * Set a user-supplied callback function to report download progress
     *
     * The callback function is used to report incremental progress of a data
     * download functions (e.g. $container->list_objects(), $obj->read(), etc).
     * The specified function will be periodically called with the number of
     * bytes transferred until the entire download is complete.  This callback
     * function can be useful for implementing "progress bars" for large
     * downloads.
     *
     * The specified callback function should take a single integer parameter.
     *
     * <code>
     * function read_callback($bytes_transferred) {
     *     print ">> downloaded " . $bytes_transferred . " bytes.\n";
     *     # ... do other things ...
     *     return;
     * }
     *
     * $conn = new CF_Connection($auth_obj);
     * $conn->set_read_progress_function("read_callback");
     * print_r($conn->list_containers());
     *
     * # output would look like this:
     * #
     * >> downloaded 10 bytes.
     * >> downloaded 11 bytes.
     * Array
     * (
     *      [0] => fuzzy.txt
     *      [1] => space name
     * )
     * </code>
     *
     * @param string $func_name the name of the user callback function
     */
    function set_read_progress_function($func_name)
    {
        $this->cfs_http->setReadProgressFunc($func_name);
    }

    /**
     * Set a user-supplied callback function to report upload progress
     *
     * The callback function is used to report incremental progress of a data
     * upload functions (e.g. $obj->write() call).  The specified function will
     * be periodically called with the number of bytes transferred until the
     * entire upload is complete.  This callback function can be useful
     * for implementing "progress bars" for large uploads/downloads.
     *
     * The specified callback function should take a single integer parameter.
     *
     * <code>
     * function write_callback($bytes_transferred) {
     *     print ">> uploaded " . $bytes_transferred . " bytes.\n";
     *     # ... do other things ...
     *     return;
     * }
     *
     * $conn = new CF_Connection($auth_obj);
     * $conn->set_write_progress_function("write_callback");
     * $container = $conn->create_container("stuff");
     * $obj = $container->create_object("foo");
     * $obj->write("The callback function will be called during upload.");
     *
     * # output would look like this:
     * # >> uploaded 51 bytes.
     * #
     * </code>
     *
     * @param string $func_name the name of the user callback function
     */
    function set_write_progress_function($func_name)
    {
        $this->cfs_http->setWriteProgressFunc($func_name);
    }
}

/**
 * Container operations
 *
 * Containers are storage compartments where you put your data (objects).
 * A container is similar to a directory or folder on a conventional filesystem
 * with the exception that they exist in a flat namespace, you can not create
 * containers inside of containers.
 *
 * You also have the option of marking a Container as "public" so that the
 * Objects stored in the Container are publicly available via the CDN.
 *
 * NOTE: Due to the possible overflow of PHP's 32-bit integer, the Container's
 *       object_count and size_used instance variables may either be a
 *       integer or float.
 *
 * @package php-cloudfiles
 */
class CF_Container
{
    public $cfs_http;
    public $name;
    public $object_count;
    public $size_used;

    public $cdn_enabled;
    public $cdn_uri;
    public $cdn_ttl;

    /**
     * Class constructor
     *
     * Constructor for Container
     *
     * @param obj $cfs_http HTTP connection manager
     * @param string $name name of Container
     * @param int $count number of Objects stored in this Container
     * @param int $bytes number of bytes stored in this Container
     * @throws SyntaxException invalid Container name
     */
    function __construct(&$cfs_http, $name, $count=0, $bytes=0)
    {
        if (mb_strlen($name, "UTF-8") > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException("Container name exceeds "
                . "maximum allowed length.");
        }
        if (strpos($name, "/") !== False) {
            throw new SyntaxException(
                "Container names cannot contain a '/' character.");
        }
        $this->cfs_http = $cfs_http;
        $this->name = $name;
        $this->object_count = $count;
        $this->bytes_used = $bytes;
        $this->cdn_enabled = NULL;
        $this->cdn_uri = NULL;
        $this->cdn_ttl = NULL;
        if ($this->cfs_http->getCDNMUrl() != NULL) {
            $this->_cdn_initialize();
        }
    }

    /**
     * String representation of Container
     *
     * Pretty print the Container instance.
     *
     * @return string Container details
     */
    function __toString()
    {
        $me = sprintf("name: %s, count: %.0f, bytes: %.0f",
            $this->name, $this->object_count, $this->bytes_used);
        if ($this->cfs_http->getCDNMUrl() != NULL) {
            $me .= sprintf(", cdn: %s, cdn uri: %s, cdn ttl: %.0f",
                $this->is_public() ? "Yes" : "No",
                $this->cdn_uri, $this->cdn_ttl);
        }
        return $me;
    }

    /**
     * Enable Container content to be served via CDN or modify CDN attributes
     *
     * Either enable this Container's content to be served via CDN or
     * adjust its CDN attributes.  This Container will always return the
     * same CDN-enabled URI each time it is toggled public/private/public.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_container = $conn->create_container("public");
     *
     * # CDN-enable the container and set it's TTL for a month
     * #
     * $public_container->make_public(86400*30); # 30 days (86400 seconds/day)
     * </code>
     *
     * @param int $ttl the time in seconds content will be cached in the CDN
     * @returns string the CDN enabled Container's URI
     * @throws CDNNotEnabledException CDN functionality not returned during auth
     * @throws AuthenticationException if auth token is not valid/expired
     * @throws InvalidResponseException unexpected response
     */
    function make_public($ttl=86400)
    {
        if ($this->cfs_http->getCDNMUrl() == NULL) {
            throw new CDNNotEnabledException(
                "Authentication response did not indicate CDN availability");
        }
        if ($this->cdn_uri != NULL) {
            # previously published, assume we're setting new attributes
            list($status, $reason, $cdn_uri) =
                $this->cfs_http->update_cdn_container($this->name,$ttl);
            if ($status == 404) {
                # this instance _thinks_ the container was published, but the
                # cdn management system thinks otherwise - try again with a PUT
                list($status, $reason, $cdn_uri) =
                    $this->cfs_http->add_cdn_container($this->name,$ttl);

            }
        } else {
            # publish it for first time
            list($status, $reason, $cdn_uri) =
                $this->cfs_http->add_cdn_container($this->name,$ttl);
        }
        if ($status == 401) {
            throw new AuthenticationException("Unauthorized");
        }
        if (!in_array($status, array(201,202))) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        $this->cdn_enabled = True;
        $this->cdn_ttl = $ttl;
        $this->cdn_uri = $cdn_uri;
        return $this->cdn_uri;
    }

    /**
     * Disable the CDN sharing for this container
     *
     * Use this method to disallow distribution into the CDN of this Container's
     * content.
     *
     * NOTE: Any content already cached in the CDN will continue to be served
     *       from its cache until the TTL expiration transpires.  The default
     *       TTL is typically one day, so "privatizing" the Container will take
     *       up to 24 hours before the content is purged from the CDN cache.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_container = $conn->get_container("public");
     *
     * # Disable CDN accessability
     * # ... still cached up to a month based on previous example
     * #
     * $public_container->make_private();
     * </code>
     *
     * @returns boolean True if successful
     * @throws CDNNotEnabledException CDN functionality not returned during auth
     * @throws AuthenticationException if auth token is not valid/expired
     * @throws InvalidResponseException unexpected response
     */
    function make_private()
    {
        if ($this->cfs_http->getCDNMUrl() == NULL) {
            throw new CDNNotEnabledException(
                "Authentication response did not indicate CDN availability");
        }
        list($status,$reason) = $this->cfs_http->remove_cdn_container($this->name);
        if ($status == 401) {
            throw new AuthenticationException("Unauthorized");
        }
        if (!in_array($status, array(202,404))) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        $this->cdn_enabled = False;
        $this->cdn_ttl = NULL;
        $this->cdn_uri = NULL;
        return True;
    }

    /**
     * Check if this Container is being publicly served via CDN
     *
     * Use this method to determine if the Container's content is currently
     * available through the CDN.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_container = $conn->get_container("public");
     *
     * # Display CDN accessability
     * #
     * $public_container->is_public() ? print "Yes" : print "No";
     * </code>
     *
     * @returns boolean True if enabled, False otherwise
     */
    function is_public()
    {
        return $this->cdn_enabled == True ? True : False;
    }

    /**
     * Create a new remote storage Object
     *
     * Return a new Object instance.  If the remote storage Object exists,
     * the instance's attributes are populated.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_container = $conn->get_container("public");
     *
     * # This creates a local instance of a storage object but only creates
     * # it in the storage system when the object's write() method is called.
     * #
     * $pic = $public_container->create_object("baby.jpg");
     * </code>
     *
     * @param string $obj_name name of storage Object
     * @return obj CF_Object instance
     */
    function create_object($obj_name=NULL)
    {
        return new CF_Object($this, $obj_name);
    }

    /**
     * Return an Object instance for the remote storage Object
     *
     * Given a name, return a Object instance representing the
     * remote storage object.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $public_container = $conn->get_container("public");
     *
     * # This call only fetches header information and not the content of
     * # the storage object.  Use the Object's read() or stream() methods
     * # to obtain the object's data.
     * #
     * $pic = $public_container->get_object("baby.jpg");
     * </code>
     *
     * @param string $obj_name name of storage Object
     * @return obj CF_Object instance
     */
    function get_object($obj_name=NULL)
    {
        return new CF_Object($this, $obj_name, True);
    }

    /**
     * Return a list of Objects
     *
     * Return an array of strings listing the Object names in this Container.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $images = $conn->get_container("my photos");
     *
     * # Grab the list of all storage objects
     * #
     * $all_objects = $images->list_objects();
     *
     * # Grab subsets of all storage objects
     * #
     * $first_ten = $images->list_objects(10);
     * $next_ten = $images->list_objects(10,10);
     *
     * # Grab images starting with "birthday_party" and default limit/offset
     * # to match all photos with that prefix
     * #
     * $prefixed = $images->list_objects(0,-1,"birthday_party");
     * </code>
     *
     * @param int $limit <i>optional</i> only return $limit names
     * @param int $offset <i>optional</i> subset of names starting at $offset
     * @param string $prefix <i>optional</i> Objects whose names begin with $prefix
     * @return array array of strings
     * @throws InvalidResponseException unexpected response
     */
    function list_objects($limit=0, $offset=-1, $prefix="")
    {
        list($status, $reason, $obj_list) =
            $this->cfs_http->get_container($this->name,
                    $limit, $offset, $prefix);
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        return $obj_list;
    }

    /**
     * Delete a remote storage Object
     *
     * Given an Object instance or name, permanently remove the remote Object
     * and all associated metadata.
     *
     * Example:
     * <code>
     * # ... authentication code excluded (see previous examples) ...
     * #
     * $conn = new CF_Authentication($auth);
     *
     * $images = $conn->get_container("my photos");
     *
     * # Delete specific object
     * #
     * $images->delete_object("disco_dancing.jpg");
     * </code>
     *
     * @param obj $obj name or instance of Object to delete
     * @return boolean <kbd>True</kbd> if successfully removed
     * @throws SyntaxException invalid Object name
     * @throws NoSuchObjectException remote Object does not exist
     * @throws InvalidResponseException unexpected response
     */
    function delete_object($obj)
    {
        $obj_name = NULL;
        if (is_object($obj)) {
            if (get_class($obj) == "CF_Object") {
                $obj_name = $obj->name;
            }
        }
        if (is_string($obj)) {
            $obj_name = $obj;
        }
        if (!$obj_name) {
            throw new SyntaxException("Object name not set.");
        }
        $status = $this->cfs_http->delete_object($this->name, $obj_name);
        if ($status == 404) {
            $m = "Specified object '".$this->name."/".$obj_name;
            $m.= "' did not exist to delete.";
            throw new NoSuchObjectException($m);
        }
        if ($status != 204) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        return True;
    }

    /**
     * Internal method to grab CDN/Container info if appropriate to do so
     *
     * @throws InvalidResponseException unexpected response
     */
    private function _cdn_initialize()
    {
        list($status, $reason, $cdn_enabled, $cdn_uri, $cdn_ttl) =
            $this->cfs_http->head_cdn_container($this->name);
        if (!in_array($status, array(204,404))) {
            throw new InvalidResponseException(
                "Invalid response (".$status."): ".$this->cfs_http->get_error());
        }
        $this->cdn_enabled = $cdn_enabled;
        $this->cdn_uri = $cdn_uri;
        $this->cdn_ttl = $cdn_ttl;
    }
}


/**
 * Object operations
 *
 * An Object is analogous to a file on a conventional filesystem. You can
 * read data from, or write data to your Objects. You can also associate 
 * arbitrary metadata with them.
 *
 * @package php-cloudfiles
 */
class CF_Object
{
    public $container;
    public $name;
    public $last_modified;
    public $content_type;
    public $content_length;
    public $metadata;
    private $etag;

    /**
     * Class constructor
     *
     * @param obj $container CF_Container instance
     * @param string $name name of Object
     * @param boolean $force_exists if set, throw an error if Object doesn't exist
     */
    function __construct(&$container, $name, $force_exists=False)
    {
        if (mb_strlen($name, "UTF-8") > MAX_OBJECT_NAME_LEN) {
            throw new SyntaxException("Object name exceeds "
                . "maximum allowed length.");
        }
        $this->container = $container;
        $this->name = $name;
        $this->etag = NULL;
        $this->_etag_override = False;
        $this->last_modified = NULL;
        $this->content_type = NULL;
        $this->content_length = 0;
        $this->metadata = array();
        if (!$this->_initialize() && $force_exists) {
            throw new NoSuchObjectException("No such object '".$name."'");
        }
    }

    /**
     * String representation of Object
     *
     * Pretty print the Object's location and name
     *
     * @return string Object information
     */
    function __toString()
    {
        return $this->container->name . "/" . $this->name;
    }

    /**
     * String representation of the Object's public URI
     *
     * A string representing the Object's public URI assuming that it's
     * parent Container is CDN-enabled.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * # Print out the Object's CDN URI (if it has one) in an HTML img-tag
     * #
     * print "<img src='$pic->public_uri()' />\n";
     * </code>
     *
     * @return string Object's public URI or NULL
     */
    function public_uri()
    {
        if ($this->container->cdn_enabled) {
            return $this->container->cdn_uri . "/" . $this->name;
        }
        return NULL;
    }

    /**
     * Read the remote Object's data
     *
     * Returns the Object's data.  This is useful for smaller Objects such
     * as images or office documents.  Object's with larger content should use
     * the stream() method below.
     *
     * Pass in $hdrs array to set specific custom HTTP headers such as
     * If-Match, If-None-Match, If-Modified-Since, Range, etc.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     * $data = $doc->read(); # read image content into a string variable
     * print $data;
     *
     * # Or see stream() below for a different example.
     * #
     * </code>
     *
     * @param array $hdrs user-defined headers (Range, If-Match, etc.)
     * @return string Object's data
     * @throws InvalidResponseException unexpected response
     */
    function read($hdrs=array())
    {
        list($status, $reason, $data) =
            $this->container->cfs_http->get_object_to_string($this, $hdrs);
        if (($status < 200) || ($status > 299
                && $status != 412 && $status != 304)) {
            throw new InvalidResponseException("Invalid response (".$status."): "
                . $this->container->cfs_http->get_error());
        }
        return $data;
    }

    /**
     * Streaming read of Object's data
     *
     * Given an open PHP resource (see PHP's fopen() method), fetch the Object's
     * data and write it to the open resource handle.  This is useful for
     * streaming an Object's content to the browser (videos, images) or for
     * fetching content to a local file.
     *
     * Pass in $hdrs array to set specific custom HTTP headers such as
     * If-Match, If-None-Match, If-Modified-Since, Range, etc.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * # Assuming this is a web script to display the README to the
     * # user's browser:
     * #
     * <?php
     * // grab README from storage system
     * //
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     *
     * // Hand it back to user's browser with appropriate content-type
     * //
     * header("Content-Type: " . $doc->content_type);
     * $output = fopen("php://output", "w");
     * $doc->stream($output); # stream object content to PHP's output buffer
     * fclose($output);
     * ?>
     *
     * # See read() above for a more simple example.
     * #
     * </code>
     *
     * @param resource $fp open resource for writing data to
     * @param array $hdrs user-defined headers (Range, If-Match, etc.)
     * @return string Object's data
     * @throws InvalidResponseException unexpected response
     */
    function stream(&$fp, $hdrs=array())
    {
        list($status, $reason) = 
                $this->container->cfs_http->get_object_to_stream($this,$fp,$hdrs);
        if (($status < 200) || ($status > 299
                && $status != 412 && $status != 304)) {
            throw new InvalidResponseException("Invalid response (".$status."): "
                .$reason);
        }
        return True;
    }

    /**
     * Store new Object metadata
     *
     * Write's an Object's metadata to the remote Object.  This will overwrite
     * an prior Object metadata.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     *
     * # Define new metadata for the object
     * #
     * $doc->metadata = array(
     *     "Author" => "EJ",
     *     "Subject" => "How to use the PHP tests",
     *     "Version" => "1.2.2"
     * );
     *
     * # Push the new metadata up to the storage system
     * #
     * $doc->sync_metadata();
     * </code>
     *
     * @return boolean <kbd>True</kbd> if successful, <kbd>False</kbd> otherwise
     * @throws InvalidResponseException unexpected response
     */
    function sync_metadata()
    {
        if (!empty($this->metadata)) {
            $status = $this->container->cfs_http->update_object($this);
            if ($status != 202) {
                throw new InvalidResponseException("Invalid response ("
                    .$status."): ".$this->container->cfs_http->get_error());
            }
            return True;
        }
        return False;
    }

    /**
     * Upload Object's data to Cloud Files
     *
     * Write data to the remote Object.  The $data argument can either be a
     * PHP resource open for reading (see PHP's fopen() method) or an in-memory
     * variable.  If passing in a PHP resource, you must also include the $size
     * parameter.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     *
     * # Upload placeholder text in my README
     * #
     * $doc->write("This is just placeholder text for now...");
     * </code>
     *
     * @param string|resource $data string or open resource
     * @param int $size amount of data to upload (required for resources)
     * @param boolean $verify generate, send, and compare MD5 checksums
     * @return boolean <kbd>True</kbd> when data uploaded successfully
     * @throws SyntaxException missing required parameters
     * @throws MisMatchedChecksumException $verify is set and checksums unequal
     * @throws InvalidResponseException unexpected response
     */
    function write($data=NULL, $size=0, $verify=True)
    {
        if (!$data) {
            throw new SyntaxException("Missing required data source.");
        }
        if ($verify) {
            if (!$this->_etag_override) {
                $this->etag = $this->compute_md5sum($data);
            }
        } else {
            $this->etag = NULL;
        }

        if (!$this->content_type) {
            $this->content_type = "application/octet-stream";
        }

        $close_fh = False;
        if (!is_resource($data)) {
            # hack to treat string data as a file handle
            $fp = fopen("php://memory", "r+");
            fwrite($fp, $data);
            rewind($fp);
            $close_fh = True;
            $this->content_length = strlen($data);
        } else {
            if (!$size) {
                throw new SyntaxException("Missing required size for data.");
            } else {
                $this->content_length = $size;
            }
            $fp = $data;
        }

        list($status, $reason, $etag) =
                $this->container->cfs_http->put_object($this, $fp);
        if ($status == 412) {
            if ($close_fh) { fclose($fp); }
            throw new SyntaxException("Missing Content-Type header");
        }
        if ($status == 422) {
            if ($close_fh) { fclose($fp); }
            throw new MisMatchedChecksumException(
                "Supplied and computed checksums do not match.");
        }
        if ($status != 201) {
            if ($close_fh) { fclose($fp); }
            throw new InvalidResponseException("Invalid response (".$status."): "
                . $this->container->cfs_http->get_error());
        }
        if (!$verify) {
            $this->etag = $etag;
        }
        if ($close_fh) { fclose($fp); }
        return True;
    }

    /**
     * Upload Object data from local filename
     *
     * This is a convenience function to upload the data from a local file.  A
     * True value for $verify will cause the method to compute the Object's MD5
     * checksum prior to uploading.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     *
     * # Upload my local README's content
     * #
     * $doc->load_from_filename("/home/ej/cloudfiles/readme");
     * </code>
     *
     * @param string $filename full path to local file
     * @param boolean $verify enable local/remote MD5 checksum validation
     * @return boolean <kbd>True</kbd> if data uploaded successfully
     * @throws SyntaxException missing required parameters
     * @throws MisMatchedChecksumException $verify is set and checksums unequal
     * @throws InvalidResponseException unexpected response
     * @throws IOException error opening file
     */
    function load_from_filename($filename, $verify=True)
    {
        $fp = @fopen($filename, "r");
        if (!$fp) {
            throw new IOException("Could not open file for reading: ".$filename);
        }
        $size = filesize($filename);
        $this->content_type = mime_content_type($filename);
        $this->write($fp, $size, $verify);
        fclose($fp);
        return True;
    }

    /**
     * Save Object's data to local filename
     *
     * Given a local filename, the Object's data will be written to the newly
     * created file.
     *
     * Example:
     * <code>
     * # ... authentication/connection/container code excluded
     * # ... see previous examples
     *
     * # Whoops!  I deleted my local README, let me download/save it
     * #
     * $my_docs = $conn->get_container("documents");
     * $doc = $my_docs->get_object("README");
     *
     * $doc->save_to_filename("/home/ej/cloudfiles/readme.restored");
     * </code>
     *
     * @param string $filename name of local file to write data to
     * @return boolean <kbd>True</kbd> if successful
     * @throws IOException error opening file
     * @throws InvalidResponseException unexpected response
     */
    function save_to_filename($filename)
    {
        $fp = @fopen($filename, "w");
        if (!$fp) {
            throw new IOException("Could not open file for writing: ".$filename);
        }
        $result = $this->stream($fp);
        fclose($fp);
        return $result;
    }

    /**
     * Set Object's MD5 checksum
     *
     * Manually set the Object's ETag.  Including the ETag is mandatory for
     * Cloud Files to perform end-to-end verification.  Omitting the ETag forces
     * the user to handle any data integrity checks.
     *
     * @param string $etag MD5 checksum hexidecimal string
     */
    function set_etag($etag)
    {
        $this->etag = $etag;
        $this->_etag_override = True;
    }

    /**
     * Object's MD5 checksum
     *
     * Accessor method for reading Object's private ETag attribute.
     *
     * @return string MD5 checksum hexidecimal string
     */
    function getETag()
    {
        return $this->etag;
    }

    /**
     * Compute the MD5 checksum
     *
     * Calculate the MD5 checksum on either a PHP resource or data.  The argument
     * may either be a local filename, open resource for reading, or a string.
     *
     * <b>WARNING:</b> If $data is a resource, the entire contents are read
     * into a local variable (memory) before computing the checksum!
     *
     * @param filename|obj|string $data filename, open resource, or string
     * @return string MD5 checksum hexidecimal string
     */
    function compute_md5sum(&$data)
    {

        if (is_file($data)) {
            $md5 = md5_file($data);
        } elseif (is_resource($data)) {
            # let's hope this isn't a BIG file
            $contents = stream_get_contents($data); # PHP 5 and up
            $md5 = md5($contents);
            rewind($data);
        } else {
            $md5 = md5($data);
        }
        return $md5;
    }

    /**
     * PRIVATE: fetch information about the remote Object if it exists
     */
    private function _initialize()
    {
        list($status, $reason, $etag, $last_modified, $content_type,
            $content_length, $metadata) =
                $this->container->cfs_http->head_object($this);

        if ($status == 404) {
            return False;
        }
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException("Invalid response (".$status."): "
                . $this->container->cfs_http->get_error());
        }
        $this->etag = $etag;
        $this->last_modified = $last_modified;
        $this->content_type = $content_type;
        $this->content_length = $content_length;
        $this->metadata = $metadata;
        return True;
    }
}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
