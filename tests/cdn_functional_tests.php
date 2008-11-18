<?php
require("cloudfiles.php");
require("cloudfiles_ini.php");  # account settings
require("test_utils.php");

function assert_callback($file, $line, $code)
{
    print "Assertion failed:\n";
    print "  File: " . $file . "\n";
    print "  Line: " . $line . "\n";
    print "  Code: " . $code . "\n";
    throw new Exception("error");
}

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_QUIET_EVAL, 1);
assert_options(ASSERT_CALLBACK, "assert_callback");


echo "======= AUTHENTICATING ======================================\n";
$auth = new CF_Authentication($USER,$API_KEY,$ACCOUNT,$HOST);
//$auth->setDebug(1);  # toggle to enable cURL verbose output
$auth->authenticate();
assert('$auth->storage_url != NULL');
assert('$auth->cdnm_url != NULL');
assert('$auth->auth_token != NULL');
$conn = new CF_Connection($auth);
//$conn->setDebug(1);  # toggle to enable cURL verbose output

echo "======= ACCOUNT INFO ========================================\n";
$orig_info = $conn->get_info();
assert('is_array($orig_info)');
print_r($orig_info);

$cnames = array();


echo "======= CREATE NEW TEST CONTAINER (ASCII) ===================\n";
$n1 = "cdn-ascii-test";
$ascii_cont = $conn->create_container($n1);
$cnames[$n1] = $ascii_cont;
assert('$ascii_cont');
print $ascii_cont . "\n";


echo "======= CREATE NEW GOOP CONTAINER (ASCII) ===================\n";
$n2 = "#$%^&*()-_=+{}[]\|;:'><,'";
$goop_cont = $conn->create_container($n2);
$cnames[$n2] = $goop_cont;
assert('$goop_cont');
print $goop_cont . "\n";


echo "======= CREATE NEW TEST CONTAINER (UTF-8) ===================\n";
$n3 = genUTF8(64,array(47,63)); # skip '/' and '?'
$utf8_cont = $conn->create_container($n3);
$cnames[$n3] = $utf8_cont;
assert('$utf8_cont');
print $utf8_cont . "\n";


# Test CDN-enabling each container for an hour
#
echo "======= CDN-ENABLE CONTAINERS ===============================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_public(3600);
    assert('$cont->is_public()');
    print $uri . "\n";
    print $cont . "\n";
}

echo "======= TEST CONTAINER ATTRIBUTES ===========================\n";
foreach ($cnames as $name => $cont) {
    $tcont = $conn->get_container($name);    
    print $tcont . "\n";
    print $cont . "\n";
    assert('$tcont->is_public()');
    assert('$tcont->name == $name');
    assert('$tcont->cdn_uri == $cont->cdn_uri');
    assert('$tcont->cdn_ttl == $cont->cdn_ttl');
}

echo "======= ADJUST TTL ==========================================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_public(7200);
    assert('$cont->is_public()');
    print $uri . "\n";
    print $cont . "\n";
}

echo "======= TEST CONTAINER ATTRIBUTES ===========================\n";
foreach ($cnames as $name => $cont) {
    $tcont = $conn->get_container($name);    
    print $tcont . "\n";
    print $cont . "\n";
    assert('$tcont->is_public()');
    assert('$tcont->name == $name');
    assert('$tcont->cdn_uri == $cont->cdn_uri');
    assert('$tcont->cdn_ttl == $cont->cdn_ttl');
}

echo "======= DISABLE CDN =========================================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_private();
    assert('$cont->is_enabled() == True');
    assert('$cont->is_public() == False');
    print $cont . "\n";
    $tcont = $conn->get_container($name);
    assert('$tcont->is_public() == False');
}

echo "======= CLEAN-UP AND DELETE =================================\n";
foreach ($cnames as $name => $cont) {
    $conn->delete_container($cont);
}

echo "======= CHECK ACCOUNT INFO ==================================\n";
$info = $conn->get_info();
print_r($orig_info);
print_r($info);
assert('$info == $orig_info');
print "=> Done...\n";

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
