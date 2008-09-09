<?php
require("capon.php");

$VERBOSE = True;                        # informational messages to stdout
$ACCOUNT = NULL;                        # account name
$USER    = "Username";                  # account's username
$PASS    = "Password";                  # user's password
$HOST    = NULL;                        # authentication host URL

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
$auth = new CLOUDFS_Authentication($USER,$PASS,$ACCOUNT,$HOST);
$auth->authenticate();

# Create a connection to the backend storage system
#
$conn = new CLOUDFS_Connection($auth);

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
