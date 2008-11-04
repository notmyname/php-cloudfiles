<?php
require("cloudfiles.php");
require("cloudfiles_ini.php");          # account settings

function genUTF8($len=10, $excludes=array())
{
    # generates a random iso-8859-1 string and converts it to utf-8
    # skips a few bad eggs.  Some names should exclude other characters,
    # for example, Containers can't contain a '/' character, so that can
    # be passed in with the $excludes array.
    $invalid_chars = array(
        127,128,129,130,131,132,133,134,135,136,137,138,139,140,141,142,
        143,144,145,146,147,148,149,150,151,152,153,154,155,156,157,158,159,
        );
    $r = "";
    while (strlen($r) < $len) {
        $c = rand(32,255);
        if (in_array($c, $invalid_chars)) { continue; }
        if (in_array($c, $excludes)) { continue; }
        $r .= chr($c);
    }
    return utf8_encode($r);
}

# Authenticate and make sure we get back a valid url/token
$auth = new CF_Authentication($USER,$API_KEY,$ACCOUNT,$HOST);
$auth->authenticate();

# Create a connection to the backend storage system
#
$conn = new CF_Connection($auth);

$cname = genUTF8(64, array(47,63)); # skip '/' and '?'
$oname = genUTF8(128, array(63)); # skip '?'
$metadata = array(
    genUTF8(12, array(58)) => genUTF8(18, array(58)), # skip ':'
    genUTF8(12, array(58)) => genUTF8(18, array(58)), # skip ':'
    genUTF8(12, array(58)) => genUTF8(18, array(58))  # skip ':'
);
if ($VERBOSE) {
    print "==> Container(".mb_strlen($cname,"UTF-8")."): ".$cname."\n";
    print "==> Object(".mb_strlen($oname,"UTF-8")."): ".$oname.")\n";
    print "==> Metadata: " . print_r($metadata, True);
}

$before_info = $conn->get_info();
$container = $conn->create_container($cname);
$obj = $container->create_object($oname);
$obj->metadata = $metadata;
$obj->write("This is a test string.");
$o2 = $container->get_object($oname);
$container->delete_object($oname);
$conn->delete_container($container);
$after_info = $conn->get_info();

if ($o2->metadata == $metadata) {
    print "Metadata does NOT match!!\n";
}

if ($before_info != $after_info) {
    print "Error cleaning-up!!\n";
}

if ($VERBOSE) { print "==> Done...\n"; }

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
