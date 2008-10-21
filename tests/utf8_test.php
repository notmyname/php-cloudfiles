<?php
require("cloudfiles.php");

$VERBOSE = True;                        # informational messages to stdout
$USER    = "Username";                  # Mosso Username
$PASS    = "API Key";                   # User's API Access Key
$ACCOUNT = NULL;                        # DEPRECATED: account name
$HOST    = NULL;                        # DEPRECATED: authentication host URL

function genUTF8($len=10)
{
    # generates a random iso-8859-1 string and converts
    # it to utf-8
    $r = "";
    $half_len = $len / 2;
    for ($i=0; $i < $half_len; $i++) {
        $r .= chr(rand(32,126)); # skip 127, 128-159
        $r .= chr(rand(160,255));
    }
    return utf8_encode($r);
}

# Authenticate and make sure we get back a valid url/token
$auth = new CF_Authentication($USER,$PASS,$ACCOUNT,$HOST);
$auth->authenticate();

# Create a connection to the backend storage system
#
$conn = new CF_Connection($auth);

$container_name = genUTF8(12);
$object_name = genUTF8(14);
if ($VERBOSE) {
    print "==> Test container: " . $container_name . "\n";
    print "==> Test object: " . $object_name . "\n";
}

$container = $conn->create_container($container_name);
$obj = $container->create_object($object_name);
$obj->write("This is a test string.");
$container->delete_object($object_name);
$conn->delete_container($container);

if ($VERBOSE) {echo "==> Done...\n";}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
